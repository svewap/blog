<?php

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Updates;

use T3G\AgencyPack\Blog\Constants;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\RepeatableInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * FeaturedImageUpdate
 */
class FeaturedImageUpdate implements UpgradeWizardInterface, RepeatableInterface
{
    public function getIdentifier(): string
    {
        return self::class;
    }

    public function getTitle(): string
    {
        return '[EXT:blog] Featured Image Update';
    }

    public function getDescription(): string
    {
        return '';
    }

    public function executeUpdate(): bool
    {
        $pages = $this->getEgliablePages();
        $fileReferences = $this->getEgliableFileReferences();

        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $builderPages = $connectionPool->getQueryBuilderForTable('pages');
        $builderPages
            ->update('pages')
            ->where($builderPages->expr()->in('uid', $builderPages->createNamedParameter(array_keys($pages), Connection::PARAM_INT_ARRAY)))
            ->set('featured_image', '1')
            ->set('media', '0');
        $builderPages->execute();

        $builderFileReferences = $connectionPool->getQueryBuilderForTable('sys_file_reference');
        $builderFileReferences
            ->update('sys_file_reference')
            ->where($builderFileReferences->expr()->in('uid', $builderFileReferences->createNamedParameter(array_keys($fileReferences), Connection::PARAM_INT_ARRAY)))
            ->set('fieldname', 'featured_image');
        $builderFileReferences->execute();

        return true;
    }

    public function updateNecessary(): bool
    {
        return (bool) count($this->getEgliableFileReferences());
    }

    /**
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * @return array<int,mixed>
     */
    protected function getEgliablePages(): array
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $builder = $connectionPool->getQueryBuilderForTable('pages');
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $statement = $builder
            ->select('uid', 'doktype', 'media', 'featured_image')
            ->from('pages')
            ->where(
                $builder->expr()->andX(
                    $builder->expr()->eq('doktype', $builder->createNamedParameter(Constants::DOKTYPE_BLOG_POST, \PDO::PARAM_INT)),
                    $builder->expr()->eq('featured_image', $builder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $builder->expr()->neq('media', $builder->createNamedParameter(0, \PDO::PARAM_INT))
                )
            )
            ->execute();

        $records = [];
        while ($record = $statement->fetch()) {
            $records[(int)$record['uid']] = $record;
        }

        return $records;
    }

    /**
     * @return array<int,mixed>
     */
    protected function getEgliableFileReferences(): array
    {
        $pages = $this->getEgliablePages();
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $builder = $connectionPool->getQueryBuilderForTable('sys_file_reference');
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $statement = $builder
            ->select('uid', 'tablenames', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $builder->expr()->andX(
                    $builder->expr()->eq('tablenames', $builder->createNamedParameter('pages')),
                    $builder->expr()->eq('fieldname', $builder->createNamedParameter('media')),
                    $builder->expr()->in('uid_foreign', $builder->createNamedParameter(array_keys($pages), Connection::PARAM_INT_ARRAY))
                )
            )
            ->execute();

        $records = [];
        while ($record = $statement->fetch()) {
            $records[(int)$record['uid']] = $record;
        }

        return $records;
    }
}
