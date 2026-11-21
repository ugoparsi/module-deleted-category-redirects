<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Browser\MahoServer;

uses(Tests\MahoBackendTestCase::class);

function categoryFallbackResponse(string $path, string $method = 'GET'): ResponseInterface
{
    return HttpClient::create(['max_redirects' => 0])
        ->request($method, MahoServer::baseUrl() . '/' . $path);
}

function categoryFallbackPath(Mage_Catalog_Model_Category $category, int $storeId): string
{
    $path = Mage::getModel('core/url_rewrite')->setStoreId($storeId)
        ->loadByIdPath('category/' . $category->getId())->getRequestPath();
    if ($path === null) {
        throw new RuntimeException('No rewrite for category ' . $category->getId());
    }
    return $path;
}

function categoryFallbackFixture(Mage_Catalog_Model_Category $parent, string $key, int $storeId): Mage_Catalog_Model_Category
{
    $category = Mage::getModel('catalog/category')->setStoreId(0)->setName($key)->setUrlKey($key)->setIsActive(1);
    $category->setAttributeSetId($category->getDefaultAttributeSetId())->setPath($parent->getPath())->save();
    Mage::getSingleton('catalog/url')->refreshCategoryRewrite($category->getId(), $storeId, false);
    return $category;
}

function setCategoryFallbackEnabled(Mage_Core_Model_Store $store, bool $enabled): void
{
    $path = Storefront_CategoryRedirect_Model_Observer::CONFIG_PATH;
    $store->setConfig($path, $enabled ? '1' : '0');
    Mage::getConfig()->saveConfig($path, $enabled ? '1' : '0', 'stores', (int) $store->getId());
    Mage::getConfig()->cleanCache();
}

beforeEach(function (): void {
    $this->store = Mage::app()->getDefaultStoreView();
    Mage::app()->setCurrentStore($this->store);
    $this->store->setConfig(Mage_Catalog_Helper_Category::XML_PATH_CATEGORY_URL_SUFFIX, '.html');
    $this->storeId = (int) $this->store->getId();
    Mage::getConfig()->saveConfig(Mage_Catalog_Helper_Category::XML_PATH_CATEGORY_URL_SUFFIX, '.html', 'stores', $this->storeId);
    Mage::getConfig()->cleanCache();
    $this->root = Mage::getModel('catalog/category')->load($this->store->getRootCategoryId());
    $this->key = 'demo-category-' . bin2hex(random_bytes(4));
    $this->grandparent = categoryFallbackFixture($this->root, $this->key, $this->storeId);
    $this->parent = categoryFallbackFixture($this->grandparent, 'parent', $this->storeId);
    $this->child = categoryFallbackFixture($this->parent, 'child', $this->storeId);
    Mage::getSingleton('catalog/url')->refreshCategoryRewrite($this->grandparent->getId(), $this->storeId, false);
    expect(categoryFallbackPath($this->parent, $this->storeId))->toBe($this->key . '/parent.html');
    $this->path = categoryFallbackPath($this->child, $this->storeId);
    expect($this->path)->toBe($this->key . '/parent/child.html');
});

afterEach(function (): void {
    setCategoryFallbackEnabled($this->store, false);
    $this->grandparent->delete();
    $resource = Mage::getSingleton('core/resource');
    $resource->getConnection('core_write')->delete(
        $resource->getTableName(Storefront_CategoryRedirect_Model_Observer::TABLE),
        ['request_path LIKE ?' => $this->key . '%'],
    );
    Mage::getConfig()->deleteConfig(Mage_Catalog_Helper_Category::XML_PATH_CATEGORY_URL_SUFFIX, 'stores', $this->storeId);
    Mage::getConfig()->deleteConfig(Storefront_CategoryRedirect_Model_Observer::CONFIG_PATH, 'stores', $this->storeId);
    Mage::getConfig()->cleanCache();
});

it('leaves a deleted URL at 404 when the setting is disabled', function (): void {
    $this->child->delete();

    expect(categoryFallbackResponse($this->path)->getStatusCode())->toBe(404);

    setCategoryFallbackEnabled($this->store, true);
    expect(categoryFallbackResponse($this->path)->getStatusCode())->toBe(404);
});

it('redirects an old category path to the closest currently available ancestor', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $this->child->delete();

    $expected = MahoServer::baseUrl() . '/' . categoryFallbackPath($this->parent, $this->storeId);
    foreach (['GET', 'HEAD'] as $method) {
        $response = categoryFallbackResponse($this->path, $method);
        expect($response->getStatusCode())->toBe(301)
            ->and($response->getHeaders(false)['location'])->toBe([$expected])
            ->and($response->getHeaders(false)['cache-control'][0])->toContain('no-store');
    }
    expect(categoryFallbackResponse($this->path, 'POST')->getStatusCode())->toBe(302);

    $this->parent->setUrlKey('renamed')->save();
    Mage::getSingleton('catalog/url')->refreshCategoryRewrite($this->parent->getId(), $this->storeId, false);
    $renamed = categoryFallbackPath($this->parent, $this->storeId);
    expect(categoryFallbackResponse($this->path)->getHeaders(false)['location'])->toBe([MahoServer::baseUrl() . '/' . $renamed]);

    $this->parent->delete();
    expect(categoryFallbackResponse($this->path)->getHeaders(false)['location'])
        ->toBe([MahoServer::baseUrl() . '/' . categoryFallbackPath($this->grandparent, $this->storeId)]);
});

it('keeps the 404 when the only parent is the root category', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $category = categoryFallbackFixture($this->root, $this->key . '-top', $this->storeId);
    $path = categoryFallbackPath($category, $this->storeId);
    $category->delete();

    expect(categoryFallbackResponse($path)->getStatusCode())->toBe(404);
});

it('captures descendants and lets a new category reclaim the original path', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $descendant = categoryFallbackFixture($this->child, 'descendant', $this->storeId);
    Mage::getSingleton('catalog/url')->refreshCategoryRewrite($this->grandparent->getId(), $this->storeId, false);
    $descendantPath = categoryFallbackPath($descendant, $this->storeId);
    $this->child->delete();

    $response = categoryFallbackResponse($descendantPath);
    expect($response->getStatusCode())->toBe(301)
        ->and($response->getHeaders(false)['location'])
        ->toBe([MahoServer::baseUrl() . '/' . categoryFallbackPath($this->parent, $this->storeId)]);

    $replacement = categoryFallbackFixture($this->parent, 'child', $this->storeId);
    expect(categoryFallbackPath($replacement, $this->storeId))->toBe($this->path)
        ->and(categoryFallbackResponse($this->path)->getStatusCode())->toBe(200);
});

it('respects a native 410 and a merchant rewrite that owns the path', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $this->child->delete();
    $gone = Mage::getResourceSingleton('core/url_gone');
    $gone->markGone([['store_id' => $this->storeId, 'request_path' => $this->path]], 'product');
    $redirect = Mage::getModel('core/url_rewrite')->setStoreId($this->storeId)
        ->setIdPath($this->key . '/merchant')->setIsSystem(false)
        ->setRequestPath($this->path)->setOptions('R')
        ->setTargetPath(categoryFallbackPath($this->grandparent, $this->storeId));

    try {
        expect(categoryFallbackResponse($this->path)->getStatusCode())->toBe(410);
        $redirect->save();
        $response = categoryFallbackResponse($this->path);
        expect($response->getStatusCode())->toBe(302)
            ->and($response->getHeaders(false)['location'][0])->toEndWith('/' . categoryFallbackPath($this->grandparent, $this->storeId));

        $redirect->setOptions('')->setTargetPath('cms/index/noroute')->save();
        expect(categoryFallbackResponse($this->path)->getStatusCode())->toBe(404);
    } finally {
        if ($redirect->getId()) {
            $redirect->delete();
        }
        Mage::getModel('core/url_gone')->getCollection()->addFieldToFilter('request_path', $this->path)->walk('delete');
    }
});

it('lets a CMS page reuse the path and resumes the fallback after the page is removed', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $this->child->delete();
    $page = Mage::getModel('cms/page')->setIdentifier($this->path)->setTitle('Demo content')
        ->setContent('<p>Demo category replacement</p>')->setIsActive()->setStores([$this->storeId])->save();

    try {
        $response = categoryFallbackResponse($this->path);
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toContain('Demo category replacement');
    } finally {
        $page->delete();
    }

    $response = categoryFallbackResponse($this->path);
    expect($response->getStatusCode())->toBe(301)
        ->and($response->getHeaders(false)['location'])
        ->toBe([MahoServer::baseUrl() . '/' . categoryFallbackPath($this->parent, $this->storeId)]);
});

it('uses the newer ancestor when a replacement category is deleted', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $this->child->delete();
    $this->parent->setUrlKey('previous-parent')->setSaveRewritesHistory(false)->save();
    Mage::getSingleton('catalog/url')->refreshCategoryRewrite($this->grandparent->getId(), $this->storeId, false);
    $oldTarget = categoryFallbackPath($this->parent, $this->storeId);

    $parent = categoryFallbackFixture($this->grandparent, 'parent', $this->storeId);
    $child = categoryFallbackFixture($parent, 'child', $this->storeId);
    Mage::getSingleton('catalog/url')->refreshCategoryRewrite($this->grandparent->getId(), $this->storeId, false);
    expect(categoryFallbackPath($child, $this->storeId))->toBe($this->path)
        ->and(categoryFallbackResponse($this->path)->getStatusCode())->toBe(200);

    $child->delete();
    $target = categoryFallbackPath($parent, $this->storeId);
    $response = categoryFallbackResponse($this->path);
    expect($target)->not->toBe($oldTarget)
        ->and($response->getStatusCode())->toBe(301)
        ->and($response->getHeaders(false)['location'])->toBe([MahoServer::baseUrl() . '/' . $target]);
});

it('records and resolves the path only in the enabled store view', function (): void {
    $other = Mage::getModel('core/store')->setCode('demo_catalog_view')
        ->setName('Demo alternate catalog')->setWebsiteId((int) $this->store->getWebsiteId())
        ->setGroupId((int) $this->store->getGroupId())->setIsActive()->save();
    Mage::app()->reinitStores();
    Mage::unregister('_singleton/catalog/url');
    try {
        $otherStoreId = (int) $other->getId();
        $store = Mage::app()->getStore($otherStoreId);
        $store->setConfig(Mage_Catalog_Helper_Category::XML_PATH_CATEGORY_URL_SUFFIX, '.html');
        Mage::getConfig()->saveConfig(Mage_Catalog_Helper_Category::XML_PATH_CATEGORY_URL_SUFFIX, '.html', 'stores', $otherStoreId);
        setCategoryFallbackEnabled($store, true);
        $localizedParent = Mage::getModel('catalog/category')->setStoreId($otherStoreId)->load($this->parent->getId());
        $localizedParent->setUrlKey('localized-parent')->save();
        Mage::getModel('catalog/url')->refreshCategoryRewrite($this->grandparent->getId(), $otherStoreId, false);
        $source = categoryFallbackPath($this->child, $otherStoreId);
        $target = categoryFallbackPath($localizedParent, $otherStoreId);
        expect($source)->not->toBe($this->path);

        $this->child->delete();

        $response = categoryFallbackResponse($source . '?___store=' . $other->getCode());
        $location = MahoServer::baseUrl() . '/' . $target . '?___store=' . $other->getCode();
        expect($response->getStatusCode())->toBe(301)
            ->and($response->getHeaders(false)['location'])->toBe([$location])
            ->and(categoryFallbackResponse($target . '?___store=' . $other->getCode())->getStatusCode())->toBe(200)
            ->and(categoryFallbackResponse($this->path)->getStatusCode())->toBe(404);
    } finally {
        $other->delete();
        Mage::app()->reinitStores();
        Mage::app()->setCurrentStore($this->store);
    }
});

it('rolls back the history when the category deletion is rolled back', function (): void {
    setCategoryFallbackEnabled($this->store, true);
    $adapter = $this->child->getResource()->getWriteConnection();
    $resource = Mage::getSingleton('core/resource');
    $history = $adapter->select()->from($resource->getTableName(Storefront_CategoryRedirect_Model_Observer::TABLE), 'request_path')
        ->where('store_id = ?', $this->storeId)->where('request_path = ?', $this->path);

    $adapter->beginTransaction();
    try {
        $this->child->delete();
        expect($adapter->fetchOne($history))->toBe($this->path);
    } finally {
        $adapter->rollBack();
    }

    expect($adapter->fetchOne($history))->toBeFalse()
        ->and(categoryFallbackResponse($this->path)->getStatusCode())->toBe(200);
});
