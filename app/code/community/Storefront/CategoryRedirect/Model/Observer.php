<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Maho\Config\Observer;
use Maho\Event\Observer as Event;

final class Storefront_CategoryRedirect_Model_Observer
{
    public const CONFIG_PATH = 'catalog/seo/redirect_deleted_category_to_parent';
    public const TABLE = 'category_redirect_history';

    #[Observer('catalog_category_delete_before')]
    public function recordPaths(Event $event): void
    {
        /** @var Mage_Catalog_Model_Category $category */
        $category = $event->getData('category');
        $ancestorIds = implode(',', array_reverse($category->getParentIds()));
        $storeIds = [];
        foreach (Mage::app()->getStores() as $store) {
            if (Mage::getStoreConfigFlag(self::CONFIG_PATH, $store)) {
                $storeIds[] = $store->getId();
            }
        }
        if ($storeIds === [] || $ancestorIds === '') {
            return;
        }

        $path = (string) $category->getPath();
        $resource = $category->getResource();
        $adapter = $resource->getWriteConnection();
        $resourceConfig = Mage::getSingleton('core/resource');
        $select = $adapter->select()
            ->from(['r' => $resourceConfig->getTableName('core/url_rewrite')], ['store_id', 'request_path'])
            ->joinInner(['c' => $resource->getEntityTable()], 'c.entity_id = r.category_id', [])
            ->where('c.path = :path OR c.path LIKE :descendants')
            ->where('r.store_id IN (?)', $storeIds)
            ->where('r.product_id IS NULL')
            ->where('r.is_system = ?', 1);
        $rows = [];
        foreach ($adapter->fetchAll($select, ['path' => $path, 'descendants' => $path . '/%']) as $rewrite) {
            $requestPath = mb_strtolower(trim((string) $rewrite['request_path']));
            if ($requestPath === '') {
                continue;
            }
            $rows[] = [
                'store_id' => (int) $rewrite['store_id'],
                'request_path' => $requestPath,
                'ancestor_ids' => $ancestorIds,
            ];
        }
        if ($rows !== []) {
            $adapter->insertOnDuplicate($resourceConfig->getTableName(self::TABLE), $rows, ['ancestor_ids']);
        }
    }

    #[Observer('controller_front_send_response_before', area: 'frontend')]
    public function redirectMissingPath(Event $event): void
    {
        /** @var Mage_Core_Controller_Varien_Front $front */
        $front = $event->getData('front');
        $request = $front->getRequest();
        $response = $front->getResponse();
        $store = Mage::app()->getStore();
        if (!Mage::getStoreConfigFlag(self::CONFIG_PATH, $store)
            || $response->getHttpResponseCode() !== 404
            || $response->hasHeader('Location')
            || !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || $request->getModuleName() !== 'cms'
            || $request->getControllerName() !== 'index'
            || !in_array(strtolower((string) $request->getActionName()), ['noroute', 'defaultnoroute'], true)
            || $request->getAlias(Mage_Core_Model_Url_Rewrite::REWRITE_REQUEST_PATH_ALIAS) !== null
        ) {
            return;
        }

        $path = mb_strtolower($request->getOriginalPathInfo());
        if (trim($path, '/') === '') {
            return;
        }

        $storeId = (int) $store->getId();
        $paths = Mage::helper('core/url')->getRequestPathCandidates($path);
        $ancestorsByPath = $this->findAncestors($storeId, $paths);
        foreach ($paths as $candidate) {
            if (!isset($ancestorsByPath[$candidate])) {
                continue;
            }
            $url = $this->findTarget($store, $ancestorsByPath[$candidate], $paths);
            if ($url !== null) {
                $response->clearBody()
                    ->setRedirect($url, 301)
                    ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                    ->setHeader('Pragma', 'no-cache');
            }
            return;
        }
    }

    /**
     * @param list<string> $paths
     * @return array<string, string>
     */
    private function findAncestors(int $storeId, array $paths): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter = $resource->getConnection('core_read');
        $select = $adapter->select()
            ->from($resource->getTableName(self::TABLE), ['request_path', 'ancestor_ids'])
            ->where('store_id = ?', $storeId)
            ->where('request_path IN (?)', $paths);
        return $adapter->fetchPairs($select);
    }

    /** @param list<string> $sourcePaths */
    private function findTarget(Mage_Core_Model_Store $store, string $ancestorIds, array $sourcePaths): ?string
    {
        $storeId = (int) $store->getId();
        $categories = Mage::getService('catalog/category');
        foreach (explode(',', $ancestorIds) as $ancestorId) {
            $categoryId = (int) $ancestorId;
            if ($categoryId === (int) $store->getRootCategoryId()) {
                break;
            }
            try {
                $categories->getVisibleById($categoryId, $storeId);
            } catch (Mage_Core_Exception_NoSuchEntity) {
                continue;
            }

            $rewrite = Mage::getModel('core/url_rewrite')->setStoreId($storeId)->loadByIdPath('category/' . $categoryId);
            $targetPath = $rewrite->getRequestPath();
            if (!$rewrite->getIsSystem() || !$targetPath
                || $rewrite->hasOption('R') || $rewrite->hasOption('RP')
                || in_array(mb_strtolower($targetPath), $sourcePaths, true)
            ) {
                continue;
            }

            return Mage::getModel('core/url')->getDirectUrl($targetPath, [
                '_store' => $storeId,
                '_nosid' => true,
                '_query' => Mage::helper('core/url')->getCanonicalStoreQuery($store),
            ]);
        }
        return null;
    }
}
