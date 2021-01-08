<?php
declare(strict_types = 1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Domain\Repository;

use Psr\Http\Message\ServerRequestInterface;
use T3G\AgencyPack\Blog\Constants;
use T3G\AgencyPack\Blog\Domain\Model\Author;
use T3G\AgencyPack\Blog\Domain\Model\Category;
use T3G\AgencyPack\Blog\Domain\Model\Post;
use T3G\AgencyPack\Blog\Domain\Model\Tag;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\ComparisonInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class PostRepository extends Repository
{
    /**
     * @var array<int,mixed>
     */
    protected $defaultConstraints = [];

    /**
     * @throws \Exception
     */
    public function initializeObject(): void
    {
        /** @var Typo3QuerySettings $querySettings */
        $querySettings = $this->objectManager->get(Typo3QuerySettings::class);
        // don't add the pid constraint
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
        $query = $this->createQuery();

        $this->defaultConstraints[] = $query->equals('doktype', Constants::DOKTYPE_BLOG_POST);
        /** @var Context $context */
        $context = GeneralUtility::makeInstance(Context::class);
        /** @var LanguageAspect $languageAspect */
        $languageAspect = $context->getAspect('language');
        if ($languageAspect->getId() === 0) {
            $this->defaultConstraints[] = $query->logicalOr([
                $query->equals('l18n_cfg', 0),
                $query->equals('l18n_cfg', 2)
            ]);
        } else {
            $this->defaultConstraints[] = $query->lessThan('l18n_cfg', 2);
        }
        $this->defaultOrderings = [
            'publish_date' => QueryInterface::ORDER_DESCENDING,
        ];
    }

    /**
     * @return null|Post
     */
    public function findByUidRespectQuerySettings(int $uid): ?Post
    {
        $query = $this->createQuery();
        $query->matching($query->equals('uid', $uid));
        /** @var QueryResultInterface $result */
        $result = $query->execute();
        /** @var null|Post $post */
        $post = $result->getFirst();
        return $post;
    }

    /**
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findAll(): QueryResultInterface
    {
        /** @var QueryResultInterface $result */
        $result = $this->getFindAllQuery()->execute();
        return $result;
    }

    /**
     * @param int $blogSetup
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findAllByPid(int $blogSetup = null): QueryResultInterface
    {
        $query = $this->getFindAllQuery();

        if ($blogSetup !== null) {
            $existingConstraint = $query->getConstraint();
            $additionalConstraint = $query->equals('pid', $blogSetup);
            $query->matching($query->logicalAnd([
                $existingConstraint,
                $additionalConstraint
            ]));
        }

        /** @var QueryResultInterface $result */
        $result = $query->execute();

        return $result;
    }

    /**
     * @param int $limit
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException#
     */
    public function findAllWithLimit(int $limit): QueryResultInterface
    {
        $query = $this->getFindAllQuery();
        $query->setLimit($limit);

        /** @var QueryResultInterface $result */
        $result = $query->execute();

        return $result;
    }

    /**
     * @return QueryInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     * @throws \TYPO3\CMS\Core\Context\Exception\AspectNotFoundException
     */
    protected function getFindAllQuery(): QueryInterface
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;
        $storagePidConstraint = $this->getStoragePidConstraint();
        if ($storagePidConstraint instanceof ComparisonInterface) {
            $constraints[] = $storagePidConstraint;
        }
        $constraints[] = $query->logicalOr([
            $query->equals('archiveDate', 0),
            $query->greaterThanOrEqual('archiveDate', time()),
        ]);

        $query->matching($query->logicalAnd($constraints));

        return $query;
    }

    /**
     * @param Author $author
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findAllByAuthor(Author $author): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;
        $storagePidConstraint = $this->getStoragePidConstraint();
        if ($storagePidConstraint instanceof ComparisonInterface) {
            $constraints[] = $storagePidConstraint;
        }
        $constraints[] = $query->contains('authors', $author);

        /** @var QueryResultInterface $result */
        $result = $query->matching($query->logicalAnd($constraints))->execute();

        return $result;
    }

    /**
     * @param Category $category
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findAllByCategory(Category $category)
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;
        $constraints[] = $query->contains('categories', $category);
        $storagePidConstraint = $this->getStoragePidConstraint();
        if ($storagePidConstraint instanceof ComparisonInterface) {
            $constraints[] = $storagePidConstraint;
        }

        /** @var QueryResultInterface $result */
        $result = $query->matching($query->logicalAnd($constraints))->execute();

        return $result;
    }

    /**
     * @param Tag $tag
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findAllByTag(Tag $tag): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;
        $constraints[] = $query->contains('tags', $tag);
        $storagePidConstraint = $this->getStoragePidConstraint();
        if ($storagePidConstraint instanceof ComparisonInterface) {
            $constraints[] = $storagePidConstraint;
        }

        /** @var QueryResultInterface $result */
        $result = $query->matching($query->logicalAnd($constraints))->execute();

        return $result;
    }

    /**
     * @param int $year
     * @param int $month
     * @return \TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     * @throws \Exception
     */
    public function findByMonthAndYear(int $year, int $month = null)
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;
        $storagePidConstraint = $this->getStoragePidConstraint();
        if ($storagePidConstraint instanceof ComparisonInterface) {
            $constraints[] = $storagePidConstraint;
        }

        if ($month !== null) {
            $startDate = new \DateTimeImmutable(sprintf('%d-%d-1 00:00:00', $year, $month));
            $endDate = new \DateTimeImmutable(sprintf('%d-%d-%d 23:59:59', $year, $month, (int)$startDate->format('t')));
        } else {
            $startDate = new \DateTimeImmutable(sprintf('%d-1-1 00:00:00', $year));
            $endDate = new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year));
        }
        $constraints[] = $query->greaterThanOrEqual('publish_date', $startDate->getTimestamp());
        $constraints[] = $query->lessThanOrEqual('publish_date', $endDate->getTimestamp());

        /** @var QueryResultInterface $result */
        $result = $query->matching($query->logicalAnd($constraints))->execute();

        return $result;
    }

    /**
     * @return Post
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findCurrentPost(): ?Post
    {
        $typoScriptFrontendController = $this->getTypoScriptFrontendController();
        $pageId = $typoScriptFrontendController
            ? (int)$typoScriptFrontendController->id
            : (int)GeneralUtility::_GP('id');

        /** @var Context $context */
        $context = GeneralUtility::makeInstance(Context::class);
        $currentLanguageId = (int)$context->getPropertyFromAspect('language', 'id', 0);

        $post = $this->getPostWithLanguage($pageId, $currentLanguageId);

        if ($post !== null) {
            return $post;
        }

        return $this->applyLanguageFallback($pageId, $currentLanguageId);
    }

    protected function getPostWithLanguage(int $pageId, int $languageId): ?Post
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;

        if ($languageId > 0) {
            $constraints[] = $query->equals('l10n_parent', $pageId);
            $constraints[] = $query->equals('sys_language_uid', $languageId);
        } else {
            $constraints[] = $query->equals('uid', $pageId);
        }

        /** @var QueryResultInterface $result */
        $result = $query
            ->matching($query->logicalAnd($constraints))
            ->execute();
        /** @var null|Post $post */
        $post = $result->getFirst();
        return $post;
    }

    /**
     * @param int $pageId the uid of the page for which fallback languages should be resolved
     * @param int $currentLanguageId the requested language, for which fallback languages should be resolved
     * @return Post|null
     */
    protected function applyLanguageFallback(int $pageId, int $currentLanguageId): ?Post
    {
        $currentSite = $this->getCurrentSite();
        if ($currentSite) {
            /** @var SiteLanguage $languageConfiguration */
            $languageConfiguration = $currentSite->getAllLanguages()[$currentLanguageId];
            // check the whole language-fallback chain
            $fallbacks = $languageConfiguration->getFallbackLanguageIds();
            foreach ($fallbacks as $fallbackLanguageId) {
                $post = $this->getPostWithLanguage($pageId, $fallbackLanguageId);
                if ($post !== null) {
                    return $post;
                }
            }
        }
        return null;
    }

    protected function getCurrentSite(): ?Site
    {
        if ($GLOBALS['TYPO3_REQUEST'] instanceof ServerRequestInterface
            && $GLOBALS['TYPO3_REQUEST']->getAttribute('site') instanceof Site) {
            return $GLOBALS['TYPO3_REQUEST']->getAttribute('site');
        }
        return null;
    }

    /**
     * Get month and years with posts.
     *
     * @return array
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    public function findMonthsAndYearsWithPosts(): array
    {
        $query = $this->createQuery();
        $constraints = $this->defaultConstraints;
        $storagePidConstraint = $this->getStoragePidConstraint();
        if ($storagePidConstraint instanceof ComparisonInterface) {
            $constraints[] = $storagePidConstraint;
        }
        $constraints[] = $query->greaterThan('crdateMonth', 0);
        $constraints[] = $query->greaterThan('crdateYear', 0);
        $query->matching($query->logicalAnd($constraints));
        $posts = $query->execute(true);

        $result = [];
        $currentIndex = -1;
        $currentYear = null;
        $currentMonth = null;
        foreach ($posts as $post) {
            $year = $post['crdate_year'];
            $month = $post['crdate_month'];
            if ($currentYear !== $year || $currentMonth !== $month) {
                $currentIndex++;
                $currentYear = $year;
                $currentMonth = $month;
                $result[$currentIndex] = [
                    'year' => $currentYear,
                    'month' => $currentMonth,
                    'count' => 1
                ];
            } else {
                $result[$currentIndex]['count']++;
            }
        }

        return $result;
    }

    /**
     * @param int $categoryMultiplier
     * @param int $tagMultiplier
     * @param int $limit
     * @return ObjectStorage
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    public function findRelatedPosts(int $categoryMultiplier = 1, int $tagMultiplier = 1, int $limit = 5): ObjectStorage
    {
        if ($categoryMultiplier === 0 && $tagMultiplier === 0) {
            $categoryMultiplier = 1;
        }

        $selectedPosts = [];
        /** @var ObjectStorage $posts */
        $posts = GeneralUtility::makeInstance(ObjectStorage::class);

        $currentPost = $this->findCurrentPost();
        if ($currentPost instanceof Post) {
            /** @var Category $category */
            foreach ($currentPost->getCategories() as $category) {
                $postsOfCategory = $this->findAllByCategory($category);
                /** @var Post $postOfCategory */
                foreach ($postsOfCategory as $postOfCategory) {
                    if ($postOfCategory->getUid() === $currentPost->getUid()) {
                        continue;
                    }

                    if (!array_key_exists((int)$postOfCategory->getUid(), $selectedPosts)) {
                        $selectedPosts[$postOfCategory->getUid()] = $categoryMultiplier;
                    } else {
                        $selectedPosts[$postOfCategory->getUid()] += $categoryMultiplier;
                    }
                }
            }

            /** @var Tag $tag */
            foreach ($currentPost->getTags() as $tag) {
                $postsOfTag = $this->findAllByTag($tag);
                /** @var Post $postOfTag */
                foreach ($postsOfTag as $postOfTag) {
                    if ($postOfTag->getUid() === $currentPost->getUid()) {
                        continue;
                    }

                    if (!array_key_exists((int)$postOfTag->getUid(), $selectedPosts)) {
                        $selectedPosts[(int)$postOfTag->getUid()] = $tagMultiplier;
                    } else {
                        $selectedPosts[(int)$postOfTag->getUid()] += $tagMultiplier;
                    }
                }
            }
        }

        arsort($selectedPosts);
        $i = 0;
        foreach ($selectedPosts as $selectedPost => $count) {
            if ($i === $limit) {
                break;
            }
            $posts->attach($this->findByUid((int)$selectedPost));
            $i++;
        }

        return $posts;
    }

    /**
     * @return array
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function getStoragePidsFromTypoScript(): array
    {
        /** @var ConfigurationManager $configurationManager */
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $settings = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);

        return GeneralUtility::intExplode(',', $settings['persistence']['storagePid']);
    }

    /**
     * @return null|ComparisonInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException
     */
    protected function getStoragePidConstraint(): ?ComparisonInterface
    {
        if (ApplicationType::fromRequest($GLOBALS['TYPO3_REQUEST'])->isFrontend()) {
            $pids = $this->getPidsForConstraints();
            $query = $this->createQuery();
            return $query->in('pid', $pids);
        }
        return null;
    }

    /**
     * @return array
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function getPidsForConstraints(): array
    {
        // only add non empty pids (pid 0 will be removed as well
        $pids = array_filter($this->getStoragePidsFromTypoScript(), function ($v) {
            return !empty($v);
        });

        if (\count($pids) === 0) {
            /** @var RootlineUtility $rootlineUtility **/
            $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $this->getTypoScriptFrontendController()->id);
            $rootLine = $rootlineUtility->get();
            foreach ($rootLine as $value) {
                $pids[] = $value['uid'];
            }
        }

        return $pids;
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController(): ?TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'] ?? null;
    }
}
