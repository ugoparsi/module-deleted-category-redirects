<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Maho\Db\Schema;

return function (SchemaEditor $schema): void {
    $schema->addTable(
        Table::editor()
            ->setUnquotedName('category_redirect_history')
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true))
            ->addColumn(Schema::column('request_path', Types::STRING, length: 255))
            ->addColumn(Schema::column('ancestor_ids', Types::TEXT))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('store_id', 'request_path')->create())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('store_id')
                    ->setUnquotedReferencedTableName('core_store')
                    ->setUnquotedReferencedColumnNames('store_id')
                    ->setOnDeleteAction(ReferentialAction::CASCADE)
                    ->create(),
            )
            ->create(),
    );
};
