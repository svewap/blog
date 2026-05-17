<?php

declare(strict_types = 1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Backend\View;

use Psr\Http\Message\ServerRequestInterface;
use T3G\AgencyPack\Blog\Constants;
use T3G\AgencyPack\Blog\Domain\Model\Author;
use T3G\AgencyPack\Blog\Domain\Model\Category;
use T3G\AgencyPack\Blog\Domain\Model\Post;
use T3G\AgencyPack\Blog\Domain\Model\Tag;
use T3G\AgencyPack\Blog\Domain\Repository\PostRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Domain\Model\FileReference as ExtbaseFileReference;

class BlogPostHeaderContentRenderer implements SingletonInterface
{
    public function __construct(
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly PostRepository $postRepository,
        protected readonly ViewFactoryInterface $viewFactory,
        protected readonly ConnectionPool $connectionPool,
    ) {
    }

    public function render(ServerRequestInterface $request): string
    {
        $blogConfiguration = $this->extensionConfiguration->get('blog');
        if ((bool)($blogConfiguration['disablePageLayoutHeader'] ?? true)) {
            return '';
        }

        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $pageInfo = BackendUtility::readPageAccess($pageUid, $GLOBALS['BE_USER']->getPagePermsClause(Permission::PAGE_SHOW));
        if (($pageInfo['doktype'] ?? 0) !== Constants::DOKTYPE_BLOG_POST) {
            return '';
        }

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:blog/Resources/Public/Css/pagelayout.min.css', 'stylesheet', 'all', '', false);

        // Skip the Extbase PostRepository for the page-layout header: the full
        // DataMapper hydration (Post + Author + Tag + Category + FileReference,
        // 100+ frames) routinely costs 4–40 s per BE request on real-world
        // datasets. Load the few fields the header actually needs via DBAL and
        // hydrate a minimal Post entity that the existing template can consume.
        $post = $this->loadPostForHeader($pageUid);

        // Template
        $view = $this->getTemplateObject($request);
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'pageInfo' => $pageInfo,
            'post' => $post,
        ]);

        return $view->render('PageLayout/Header');
    }

    protected function loadPostForHeader(int $pageUid): ?Post
    {
        $pageRow = $this->connectionPool->getConnectionForTable('pages')
            ->select(['*'], 'pages', ['uid' => $pageUid])
            ->fetchAssociative();
        if (!$pageRow || (int)($pageRow['doktype'] ?? 0) !== Constants::DOKTYPE_BLOG_POST) {
            return null;
        }

        $post = new Post();
        $post->_setProperty('uid', (int)$pageRow['uid']);
        $post->setPid((int)($pageRow['pid'] ?? 0));
        $post->setTitle((string)($pageRow['title'] ?? ''));
        $post->setSubtitle((string)($pageRow['subtitle'] ?? ''));
        $post->setAbstract((string)($pageRow['abstract'] ?? ''));
        $post->setDescription((string)($pageRow['description'] ?? ''));
        $post->setPublishDate((int)($pageRow['publish_date'] ?? 0));
        $post->setArchiveDate((int)($pageRow['archive_date'] ?? 0));

        foreach ($this->loadAuthors($pageUid) as $row) {
            $author = new Author();
            $author->_setProperty('uid', (int)$row['uid']);
            $author->setName((string)($row['name'] ?? ''));
            $author->setEmail((string)($row['email'] ?? ''));
            $post->addAuthor($author);
        }

        foreach ($this->loadTags($pageUid) as $row) {
            $tag = new Tag();
            $tag->_setProperty('uid', (int)$row['uid']);
            $tag->setTitle((string)($row['title'] ?? ''));
            $post->addTag($tag);
        }

        foreach ($this->loadCategories($pageUid) as $row) {
            $category = new Category();
            $category->_setProperty('uid', (int)$row['uid']);
            $category->setTitle((string)($row['title'] ?? ''));
            $post->addCategory($category);
        }

        $featuredImageUid = $this->loadFeaturedImageReferenceUid($pageUid);
        if ($featuredImageUid !== null) {
            $extbaseRef = new ExtbaseFileReference();
            // _localizedUid is what Extbase\FileReference::getOriginalResource()
            // uses to lazy-resolve the FAL FileReference via ResourceFactory.
            $extbaseRef->_setProperty('_localizedUid', $featuredImageUid);
            $extbaseRef->_setProperty('uid', $featuredImageUid);
            $post->setFeaturedImage($extbaseRef);
        }

        return $post;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadAuthors(int $pageUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_blog_domain_model_author');
        // Authors-MM-Table lives between pages and tx_blog_domain_model_author.
        // Restrict to non-deleted/non-hidden authors so the BE header matches
        // what the FE would render.
        return $qb
            ->select('a.uid', 'a.name', 'a.email')
            ->from('tx_blog_domain_model_author', 'a')
            ->join('a', 'tx_blog_post_author_mm', 'mm', 'mm.uid_foreign = a.uid')
            ->where(
                $qb->expr()->eq('mm.uid_local', $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->orderBy('mm.sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadTags(int $pageUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_blog_domain_model_tag');
        return $qb
            ->select('t.uid', 't.title')
            ->from('tx_blog_domain_model_tag', 't')
            ->join('t', 'tx_blog_tag_pages_mm', 'mm', 'mm.uid_local = t.uid')
            ->where(
                $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->orderBy('mm.sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadCategories(int $pageUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        return $qb
            ->select('c.uid', 'c.title')
            ->from('sys_category', 'c')
            ->join('c', 'sys_category_record_mm', 'mm', 'mm.uid_local = c.uid')
            ->where(
                $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter('pages')),
                $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories'))
            )
            ->orderBy('mm.sorting_foreign')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    protected function loadFeaturedImageReferenceUid(int $pageUid): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $row = $qb
            ->select('r.uid')
            ->from('sys_file_reference', 'r')
            ->where(
                $qb->expr()->eq('r.uid_foreign', $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('r.tablenames', $qb->createNamedParameter('pages')),
                $qb->expr()->eq('r.fieldname', $qb->createNamedParameter('featured_image'))
            )
            ->orderBy('r.sorting_foreign')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ? (int)$row['uid'] : null;
    }

    protected function getTemplateObject(ServerRequestInterface $request): ViewInterface
    {
        return $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [GeneralUtility::getFileAbsFileName('EXT:blog/Resources/Private/Templates')],
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:blog/Resources/Private/Partials')],
            layoutRootPaths: [GeneralUtility::getFileAbsFileName('EXT:blog/Resources/Private/Layouts')],
            request: $request,
        ));
    }
}
