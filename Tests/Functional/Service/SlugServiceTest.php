<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Redirects\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Model\CorrelationId;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Redirects\Event\AfterAutoCreateRedirectHasBeenPersistedEvent;
use TYPO3\CMS\Redirects\Event\ModifyAutoCreateRedirectRecordBeforePersistingEvent;
use TYPO3\CMS\Redirects\RedirectUpdate\SlugRedirectChangeItem;
use TYPO3\CMS\Redirects\RedirectUpdate\SlugRedirectChangeItemFactory;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\CMS\Redirects\Service\SlugService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * @todo Tests in this TestCase simulates what happens in the corresponding `DataHandlerSlugUpdateHook`, mainly which
 *       is executed in which order. This is somehow clumsy. Either cover proper DataHandler hook execution with
 *       additional tests avoiding the simulation and testing SlugService in indirect way - or refactor them here.
 */
final class SlugServiceTest extends FunctionalTestCase
{
    private array $languages = [
        [
            'title' => 'English',
            'enabled' => true,
            'languageId' => '0',
            'base' => '/en/',
            'locale' => 'en_US.UTF-8',
            'navigationTitle' => 'English',
            'flag' => 'us',
        ],
        [
            'title' => 'German',
            'enabled' => true,
            'languageId' => '1',
            'base' => 'https://de.example.com/',
            'locale' => 'de_DE.UTF-8',
            'navigationTitle' => 'German',
            'flag' => 'de',
        ],
        [
            'title' => 'Spanish',
            'enabled' => true,
            'languageId' => '2',
            'base' => '/es/',
            'locale' => 'es_ES.UTF-8',
            'navigationTitle' => 'Spanish',
            'flag' => 'es',
        ],
    ];

    protected array $coreExtensionsToLoad = ['redirects'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function buildBaseSite(): void
    {
        $configuration = [
            'rootPageId' => 1,
            'base' => '/',
        ];
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('testing', $configuration);
    }

    private function buildBaseSiteInSubfolder(): void
    {
        $configuration = [
            'rootPageId' => 1,
            'base' => '/sub-folder',
        ];
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('testing', $configuration);
    }

    private function buildBaseSiteWithLanguages(): void
    {
        $configuration = [
            'rootPageId' => 1,
            'base' => '/',
            'languages' => $this->languages,
        ];
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('testing', $configuration);
    }

    /**
     * @param array<string, mixed> $additionalConfiguration
     */
    private function buildBaseSiteWithLanguagesAndAdditionalConfiguration(array $additionalConfiguration): void
    {
        $configuration = array_merge_recursive(
            $additionalConfiguration,
            [
                'rootPageId' => 1,
                'base' => '/',
                'languages' => $this->languages,
            ]
        );
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('testing', $configuration);
    }

    private function buildBaseSiteWithLanguagesInSubFolder(): void
    {
        $languages = $this->languages;
        array_walk($languages, static function (&$languageData) {
            $languageData['base'] = (
                !str_contains($languageData['base'], 'http')
                ? $languageData['base']
                : $languageData['base'] . 'sub-folder/'
            );
        });
        $configuration = [
            'rootPageId' => 1,
            'base' => '/sub-folder',
            'languages' => $languages,
        ];
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('testing', $configuration);
    }

    private function createSubject(): SlugService
    {
        $this->get(SiteMatcher::class)->refresh();
        $this->get(SiteFinder::class)->getAllSites(false);
        $subject = new SlugService(
            context: $this->get(Context::class),
            pageRepository: $this->get(PageRepository::class),
            linkService: $this->get(LinkService::class),
            redirectCacheService: $this->get(RedirectCacheService::class),
            slugRedirectChangeItemFactory: $this->get(SlugRedirectChangeItemFactory::class),
            eventDispatcher: $this->get(EventDispatcherInterface::class),
            connectionPool: $this->getConnectionPool(),
        );
        $subject->setLogger(new NullLogger());
        return $subject;
    }

    private function assertSlugsAndRedirectsExists(array $slugs, array $redirects, bool $withRedirectUid = false): void
    {
        $pageRecords = $this->getAllRecords('pages');
        self::assertCount(count($slugs), $pageRecords);
        foreach ($pageRecords as $record) {
            self::assertContains($record['slug'], $slugs, 'unexpected slug: ' . $record['slug']);
        }

        $redirectRecords = $this->getAllRecords('sys_redirect');
        self::assertCount(count($redirects), $redirectRecords);
        foreach ($redirectRecords as $record) {
            $combination = [
                'source_host' => $record['source_host'],
                'source_path' => $record['source_path'],
                'target' => $record['target'],
            ];
            if ($withRedirectUid) {
                $combination = [
                    'uid' => $record['uid'],
                    'source_host' => $record['source_host'],
                    'source_path' => $record['source_path'],
                    'target' => $record['target'],
                ];
            }
            self::assertContains($combination, $redirects, 'wrong redirect found');
        }
    }

    private function setPageSlug(int $pageId, string $slug): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->update(
                'pages',
                [
                    'slug' => $slug,
                ],
                [
                    'uid' => $pageId,
                ]
            );
    }

    /**
     * This test should prove, that a renaming of a subtree works as expected
     * and all slugs of sub pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works for a partial tree.
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirects(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSite();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test1.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/test-new',
            '/dummy-1-3',
            '/dummy-1-4',
            '/test-new/dummy-1-2-5',
            '/test-new/dummy-1-2-6',
            '/test-new/dummy-1-2-7',
            '/dummy-1-3/dummy-1-3-8',
            '/dummy-1-3/dummy-1-3-9',
            '/dummy-1-4/dummy-1-4-10',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => '*', 'source_path' => '/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-5', 'target' => 't3://page?uid=5&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-6', 'target' => 't3://page?uid=6&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-7', 'target' => 't3://page?uid=7&_language=0'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    /**
     * This test should prove, that a renaming of a complete tree works as expected
     * and all slugs of sub pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works for a complete tree inclusive the root page.
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirectsForRootChange(): void
    {
        $newPageSlug = '/new-home';
        $this->buildBaseSite();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test2.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(1);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(1, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(1, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/new-home',
            '/new-home/dummy-1-2',
            '/new-home/dummy-1-3',
            '/new-home/dummy-1-4',
            '/new-home/dummy-1-2/dummy-1-2-5',
            '/new-home/dummy-1-2/dummy-1-2-6',
            '/new-home/dummy-1-2/dummy-1-2-7',
            '/new-home/dummy-1-3/dummy-1-3-8',
            '/new-home/dummy-1-3/dummy-1-3-9',
            '/new-home/dummy-1-4/dummy-1-4-10',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => '*', 'source_path' => '/', 'target' => 't3://page?uid=1&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-3', 'target' => 't3://page?uid=3&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-4', 'target' => 't3://page?uid=4&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-5', 'target' => 't3://page?uid=5&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-6', 'target' => 't3://page?uid=6&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-7', 'target' => 't3://page?uid=7&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-3/dummy-1-3-8', 'target' => 't3://page?uid=8&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-3/dummy-1-3-9', 'target' => 't3://page?uid=9&_language=0'],
            ['source_host' => '*', 'source_path' => '/dummy-1-4/dummy-1-4-10', 'target' => 't3://page?uid=10&_language=0'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    /**
     * This test should prove, that a renaming of a subtree works as expected
     * and all slugs of sub pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works for a setup with a base in a sub-folder.
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirectsWithSubFolderBase(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteInSubfolder();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test1.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/test-new',
            '/dummy-1-3',
            '/dummy-1-4',
            '/test-new/dummy-1-2-5',
            '/test-new/dummy-1-2-6',
            '/test-new/dummy-1-2-7',
            '/dummy-1-3/dummy-1-3-8',
            '/dummy-1-3/dummy-1-3-9',
            '/dummy-1-4/dummy-1-4-10',
        ];

        // This redirects should exists, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => '*', 'source_path' => '/sub-folder/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ['source_host' => '*', 'source_path' => '/sub-folder/dummy-1-2/dummy-1-2-5', 'target' => 't3://page?uid=5&_language=0'],
            ['source_host' => '*', 'source_path' => '/sub-folder/dummy-1-2/dummy-1-2-6', 'target' => 't3://page?uid=6&_language=0'],
            ['source_host' => '*', 'source_path' => '/sub-folder/dummy-1-2/dummy-1-2-7', 'target' => 't3://page?uid=7&_language=0'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    /**
     * This test should prove, that a renaming of a subtree works as expected
     * and all slugs of sub-pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works for a setup with languages.
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirectsWithLanguages(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test3.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(31);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(31, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(31, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/dummy-1-2',
            '/test-new',
            '/dummy-1-3',
            '/dummy-1-4',
            '/dummy-1-2/dummy-1-2-5',
            '/dummy-1-2/dummy-1-2-6',
            '/dummy-1-2/dummy-1-2-7',
            '/dummy-1-3/dummy-1-3-8',
            '/test-new/dummy-1-3-8',
            '/dummy-1-3/dummy-1-3-9',
            '/dummy-1-4/dummy-1-4-10',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => 'de.example.com', 'source_path' => '/dummy-1-3', 'target' => 't3://page?uid=3&_language=1'],
            ['source_host' => 'de.example.com', 'source_path' => '/dummy-1-3/dummy-1-3-8', 'target' => 't3://page?uid=8&_language=1'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    /**
     * This test should prove, that a renaming of a subtree works as expected
     * and all slugs of sub-pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works with languages and a base in a sub-folder.
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirectsWithLanguagesInSubFolder(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguagesInSubFolder();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test3.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(31);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(31, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(31, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/dummy-1-2',
            '/test-new',
            '/dummy-1-3',
            '/dummy-1-4',
            '/dummy-1-2/dummy-1-2-5',
            '/dummy-1-2/dummy-1-2-6',
            '/dummy-1-2/dummy-1-2-7',
            '/dummy-1-3/dummy-1-3-8',
            '/test-new/dummy-1-3-8',
            '/dummy-1-3/dummy-1-3-9',
            '/dummy-1-4/dummy-1-4-10',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => 'de.example.com', 'source_path' => '/sub-folder/dummy-1-3', 'target' => 't3://page?uid=3&_language=1'],
            ['source_host' => 'de.example.com', 'source_path' => '/sub-folder/dummy-1-3/dummy-1-3-8', 'target' => 't3://page?uid=8&_language=1'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    /**
     * This test should prove, that a renaming of a subtree works as expected
     * and all slugs of sub-pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works with languages and a base in a sub-folder.
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirectsWithDefaultLanguageInSubFolder(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguagesInSubFolder();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test3.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(3);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(3, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(3, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/dummy-1-2',
            '/test-new',
            '/dummy-1-3',
            '/dummy-1-4',
            '/dummy-1-2/dummy-1-2-5',
            '/dummy-1-2/dummy-1-2-6',
            '/dummy-1-2/dummy-1-2-7',
            '/dummy-1-3/dummy-1-3-8',
            '/test-new/dummy-1-3-8',
            '/test-new/dummy-1-3-9',
            '/dummy-1-4/dummy-1-4-10',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => '*', 'source_path' => '/sub-folder/en/dummy-1-3', 'target' => 't3://page?uid=3&_language=0'],
            ['source_host' => '*', 'source_path' => '/sub-folder/en/dummy-1-3/dummy-1-3-8', 'target' => 't3://page?uid=8&_language=0'],
            ['source_host' => '*', 'source_path' => '/sub-folder/en/dummy-1-3/dummy-1-3-9', 'target' => 't3://page?uid=9&_language=0'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    /**
     * This test should prove, that a renaming of a subtree works as expected
     * and all slugs of sub-pages are renamed and redirects are created.
     *
     * We test here that rebuildSlugsForSlugChange works when changing a L>0 siteroot which has pid=0
     */
    #[Test]
    public function rebuildSlugsForSlugChangeRenamesSubSlugsAndCreatesRedirectsWithLanguagesForSiteroot(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_pages_test4.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(5);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(5, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(5, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/dummy-1-2',
            '/dummy-1-3',
            '/dummy-1-2/dummy-1-2-3',
            '/test-new',
            '/test-new/dummy-1-2',
            '/test-new/dummy-1-3',
            '/test-new/dummy-1-2/dummy-1-2-3',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => 'de.example.com', 'source_path' => '/', 'target' => 't3://page?uid=1&_language=1'],
            ['source_host' => 'de.example.com', 'source_path' => '/dummy-1-2', 'target' => 't3://page?uid=2&_language=1'],
            ['source_host' => 'de.example.com', 'source_path' => '/dummy-1-3', 'target' => 't3://page?uid=3&_language=1'],
            ['source_host' => 'de.example.com', 'source_path' => '/dummy-1-2/dummy-1-2-3', 'target' => 't3://page?uid=4&_language=1'],
        ];

        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    #[Test]
    public function modifyAutoCreateRedirectRecordBeforePersistingIsTriggered(): void
    {
        $newPageSlug = '/test-new';
        $eventOverrideSource = '/overridden-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_ModifyAutoCreateRedirectRecordBeforePersistingEvent.csv');

        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'modify-auto-create-redirect-record-before-persisting',
            static function (ModifyAutoCreateRedirectRecordBeforePersistingEvent $event) use (
                &$modifyAutoCreateRedirectRecordBeforePersisting,
                $eventOverrideSource
            ) {
                $modifyAutoCreateRedirectRecordBeforePersisting = $event;
                $event->setRedirectRecord(
                    array_replace(
                        $event->getRedirectRecord(),
                        [
                            'source_path' => $eventOverrideSource,
                        ],
                    )
                );
            }
        );
        $listenerProvider = $container->get(ListenerProvider::class);
        $listenerProvider->addListener(ModifyAutoCreateRedirectRecordBeforePersistingEvent::class, 'modify-auto-create-redirect-record-before-persisting');

        $subject = $this->createSubject();
        /** @var SlugRedirectChangeItem $changeItem */
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        self::assertInstanceOf(ModifyAutoCreateRedirectRecordBeforePersistingEvent::class, $modifyAutoCreateRedirectRecordBeforePersisting);
        self::assertSame($eventOverrideSource, $modifyAutoCreateRedirectRecordBeforePersisting->getRedirectRecord()['source_path']);

        $this->assertSlugsAndRedirectsExists(
            [
                '/',
                $newPageSlug,
            ],
            [
                ['source_host' => '*', 'source_path' => $eventOverrideSource, 'target' => 't3://page?uid=2&_language=0'],
            ],
        );
    }

    #[Test]
    public function afterAutoCreteRedirectHasBeenPersistedIsTriggered(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_AfterAutoCreateRedirectHasBeenPersistedEvent.csv');

        /** @var Container $container */
        $container = $this->get('service_container');
        $container->set(
            'after-auto-create-redirect-has-been-persisted',
            static function (AfterAutoCreateRedirectHasBeenPersistedEvent $event) use (
                &$afterAutoCreateRedirectHasBeenPersisted
            ) {
                $afterAutoCreateRedirectHasBeenPersisted = $event;
            }
        );
        $listenerProvider = $container->get(ListenerProvider::class);
        $listenerProvider->addListener(AfterAutoCreateRedirectHasBeenPersistedEvent::class, 'after-auto-create-redirect-has-been-persisted');

        $subject = $this->createSubject();
        /** @var SlugRedirectChangeItem $changeItem */
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        self::assertInstanceOf(AfterAutoCreateRedirectHasBeenPersistedEvent::class, $afterAutoCreateRedirectHasBeenPersisted);
        self::assertSame(1, $afterAutoCreateRedirectHasBeenPersisted->getRedirectRecord()['uid'] ?? null);

        $this->assertSlugsAndRedirectsExists(
            [
                '/',
                $newPageSlug,
            ],
            [
                ['uid' => 1, 'source_host' => '*', 'source_path' => '/en/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ],
            true,
        );
    }

    /**
     * Regression test for https://forge.typo3.org/issues/103806
     */
    #[Test]
    public function defaultSlashPageTypeSuffixCreatesOnlyPageTypeZeroSourceRedirect(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguagesAndAdditionalConfiguration([
            'routeEnhancers' => [
                'PageTypeSuffix' => [
                    'type' => 'PageType',
                    'default' => '/',
                    'map' => [
                        '/' => 0,
                    ],
                ],
            ],
        ]);
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_defaultSlashPageTypeSuffixCreatesOnlyPageTypeZeroSourceRedirect.csv');

        $subject = $this->createSubject();
        /** @var SlugRedirectChangeItem $changeItem */
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        $this->assertSlugsAndRedirectsExists(
            [
                '/',
                $newPageSlug,
            ],
            [
                ['uid' => 1, 'source_host' => '*', 'source_path' => '/en/dummy-1-2/', 'target' => 't3://page?uid=2&_language=0'],
            ],
            true,
        );
    }

    /**
     * @see https://forge.typo3.org/issues/103436/
     * @see https://forge.typo3.org/issues/103798
     */
    #[Test]
    public function pageTypeZeroRedirectCreatesOnlyOneRedirectForHiddenPageSlugChange(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_hiddenPage.csv');

        $subject = $this->createSubject();
        /** @var SlugRedirectChangeItem $changeItem */
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        $this->assertSlugsAndRedirectsExists(
            [
                '/',
                $newPageSlug,
            ],
            [
                ['uid' => 1, 'source_host' => '*', 'source_path' => '/en/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ],
            true,
        );
    }

    /**
     * @see https://forge.typo3.org/issues/103436/
     * @see https://forge.typo3.org/issues/103798
     */
    #[Test]
    public function pageTypeZeroRedirectCreatesOnlyOneRedirectForPageWithReachedEndtimePageSlugChange(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_endtime.csv');

        $subject = $this->createSubject();
        /** @var SlugRedirectChangeItem $changeItem */
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        $this->assertSlugsAndRedirectsExists(
            [
                '/',
                $newPageSlug,
            ],
            [
                ['uid' => 1, 'source_host' => '*', 'source_path' => '/en/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ],
            true,
        );
    }

    /**
     * @see https://forge.typo3.org/issues/103436/
     * @see https://forge.typo3.org/issues/103798
     */
    #[Test]
    public function pageTypeZeroRedirectCreatesOnlyOneRedirectForPageNotReachedStarttimePageSlugChange(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSiteWithLanguages();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SlugServiceTest_starttime.csv');

        $subject = $this->createSubject();
        /** @var SlugRedirectChangeItem $changeItem */
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        $this->assertSlugsAndRedirectsExists(
            [
                '/',
                $newPageSlug,
            ],
            [
                ['uid' => 1, 'source_host' => '*', 'source_path' => '/en/dummy-1-2', 'target' => 't3://page?uid=2&_language=0'],
            ],
            true,
        );
    }

    #[Test]
    public function sysFolderWithSubPagesDoesNotCreateAutoRedirectForSysFolderButUpdatesSubpagesIfReasonable(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSite();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SysFolderSubPages_Test1.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/test-new',
            '/dummy-1-3',
            '/test-new/dummy-1-2-3',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-3', 'target' => 't3://page?uid=4&_language=0'],
        ];
        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    #[Test]
    public function spacerWithSubPagesDoesNotCreateAutoRedirectForSpacerButUpdatesSubpagesIfReasonable(): void
    {
        $newPageSlug = '/test-new';
        $this->buildBaseSite();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SpacerSubPages_Test1.csv');
        $subject = $this->createSubject();
        $changeItem = $this->get(SlugRedirectChangeItemFactory::class)->create(2);
        $changeItem = $changeItem->withChanged(array_merge($changeItem->getOriginal(), ['slug' => $newPageSlug]));
        $subject->rebuildSlugsForSlugChange(2, $changeItem, CorrelationId::forScope(StringUtility::getUniqueId('test')));
        $this->setPageSlug(2, $newPageSlug);

        // These are the slugs after rebuildSlugsForSlugChange() has run
        $slugs = [
            '/',
            '/test-new',
            '/dummy-1-3',
            '/test-new/dummy-1-2-3',
        ];

        // This redirects should exist, after rebuildSlugsForSlugChange() has run
        $redirects = [
            ['source_host' => '*', 'source_path' => '/dummy-1-2/dummy-1-2-3', 'target' => 't3://page?uid=4&_language=0'],
        ];
        $this->assertSlugsAndRedirectsExists($slugs, $redirects);
    }

    #[Test]
    public function relativeTargetCanBeSetUsingDataHandler(): void
    {
        $newRedirect = StringUtility::getUniqueId('NEW');
        $dataMap = [
            'sys_redirect' => [
                $newRedirect => [
                    'pid' => 1,
                    'deleted' => 0,
                    'disabled' => 0,
                    'source_host' => '*',
                    'source_path' => '/test-redirect-1/',
                    'target' => '/relative-target/',
                ],
            ],
        ];
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RelativeTargetDataHandler.csv');
        $this->buildBaseSite();

        // For testing scenario we need to allow redirect records be added to normal pages.
        $dokTypeRegistry = $this->get(PageDoktypeRegistry::class);
        $dokTypeRegistry->addAllowedRecordTypes(['sys_redirect'], PageRepository::DOKTYPE_DEFAULT);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $this->assertCSVDataSet(__DIR__ . '/Fixtures/AssertionDataSets/RelativeTargetDataHandler.csv');
    }
}
