<?php

namespace Hautelook\AliceBundle\Loader;

use Doctrine\DBAL\Sharding\PoolingShardConnection;
use Doctrine\ORM\EntityManagerInterface;
use Fidry\AliceDataFixtures\Bridge\Doctrine\Persister\ObjectManagerPersister;
use Fidry\AliceDataFixtures\Bridge\Doctrine\Purger\OrmPurger;
use Fidry\AliceDataFixtures\Loader\FileResolverLoader;
use Fidry\AliceDataFixtures\Loader\PurgerLoader;
use Fidry\AliceDataFixtures\LoaderInterface;
use Fidry\AliceDataFixtures\Persistence\PersisterAwareInterface;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Hautelook\AliceBundle\BundleResolverInterface;
use Hautelook\AliceBundle\FixtureLocatorInterface;
use Hautelook\AliceBundle\LoaderInterface as AliceBundleLoaderInterface;
use Hautelook\AliceBundle\LoggerAwareInterface;
use Hautelook\AliceBundle\Resolver\File\KernelFileResolver;
use Nelmio\Alice\IsAServiceTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

final class DoctrineOrmLoader implements AliceBundleLoaderInterface, LoggerAwareInterface
{
    use IsAServiceTrait;

    /**
     * @var BundleResolverInterface
     */
    private $bundleResolver;

    /**
     * @var FixtureLocatorInterface
     */
    private $fixtureLocator;

    /**
     * @var LoaderInterface|PersisterAwareInterface
     */
    private $loader;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(
        BundleResolverInterface $bundleResolver,
        FixtureLocatorInterface $fixtureLocator,
        LoaderInterface $loader,
        LoggerInterface $logger,
		ContainerInterface $container
    ) {
        $this->bundleResolver = $bundleResolver;
        $this->fixtureLocator = $fixtureLocator;
        if (false === $loader instanceof PersisterAwareInterface) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Expected loader to be an instance of "%s".',
                    PersisterAwareInterface::class
                )
            );
        }
        $this->loader = $loader;
        $this->logger = $logger;
        $this->container = $container;
    }

    /**
     * @inheritdoc
     */
    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->bundleResolver, $this->fixtureLocator, $this->loader, $logger);
    }

    /**
     * @inheritdoc
     */
    public function load(
        Application $application,
        EntityManagerInterface $manager,
        array $bundles,
        string $environment,
        bool $append,
        bool $purgeWithTruncate,
        string $shard = null,
        $purgeCachedDumpFile = false,
		array $fixtureFiles = null
    ) {
        $bundles = $this->bundleResolver->resolveBundles($application, $bundles);
        if (!$fixtureFiles) {
        	$fixtureFiles = $this->fixtureLocator->locateFiles($bundles, $environment);
		}

		/**
		 * If cache is enabled, search for relevant dump file
		 * If this file does not exists, create fixtures and generate dump file
		 */
		$useCache = $this->container->getParameter('hautelook_alice.use_cache');
		$cacheDir = $this->container->get('kernel')->getCacheDir();
		$dumpFile = sprintf("%s/db_fixture_dump_%s.sql", $cacheDir, md5(serialize($fixtureFiles)));

		if (file_exists($dumpFile) && $purgeCachedDumpFile) {
			unlink($dumpFile);
		}

		if ($useCache && file_exists($dumpFile)) {
			$process = new Process(sprintf("%s -h %s -u %s --password=%s %s < %s", $this->container->getParameter('hautelook_alice.mysql_binary'), $manager->getConnection()->getHost(), $manager->getConnection()->getUsername(), $manager->getConnection()->getPassword(), $manager->getConnection()->getDatabase(), $dumpFile));
			$process->run();
			return [];

		} else {
			$this->logger->info('fixtures found', ['files' => $fixtureFiles]);

			if (null !== $shard) {
				$this->connectToShardConnection($manager, $shard);
			}

			$fixtures = $this->loadFixtures(
				$this->loader,
				$application->getKernel(),
				$manager,
				$fixtureFiles,
				$application->getKernel()->getContainer()->getParameterBag()->all(),
				$append,
				$purgeWithTruncate
			);

			$this->logger->info('fixtures loaded');


			if ($useCache) {
				$process = new Process(sprintf("%s -h %s -u %s --password=%s %s > %s", $this->container->getParameter('hautelook_alice.mysqldump_binary'), $manager->getConnection()->getHost(), $manager->getConnection()->getUsername(), $manager->getConnection()->getPassword(), $manager->getConnection()->getDatabase(), $dumpFile));
				$process->run();
			}

			return $fixtures;
		}

    }

    private function connectToShardConnection(EntityManagerInterface $manager, string $shard)
    {
        $connection = $manager->getConnection();
        if ($connection instanceof PoolingShardConnection) {
            $connection->connect($shard);

            return;
        }

        throw new \InvalidArgumentException(
            sprintf(
                'Could not establish a shard connection for the shard "%s". The connection must be an instance'
                .' of "%s", got "%s" instead.',
                $shard,
                PoolingShardConnection::class,
                get_class($connection)
            )
        );
    }

    /**
     * @param LoaderInterface|PersisterAwareInterface $loader
     * @param KernelInterface                         $kernel
     * @param EntityManagerInterface                  $manager
     * @param string[]                                $files
     * @param array                                   $parameters
     * @param bool                                    $append
     * @param bool|null                               $purgeWithTruncate
     *
     * @return \object[]
     */
    private function loadFixtures(
        LoaderInterface $loader,
        KernelInterface $kernel,
        EntityManagerInterface $manager,
        array $files,
        array $parameters,
        bool $append,
        bool $purgeWithTruncate = null
    ) {
        if ($append && $purgeWithTruncate !== null) {
            throw new \LogicException(
                'Cannot append loaded fixtures and at the same time purge the database. Choose one.'
            );
        }

        $loader = $loader->withPersister(new ObjectManagerPersister($manager));
        if (true === $append) {
            return $loader->load($files, $parameters);
        }

        $purgeMode = (true === $purgeWithTruncate)
            ? PurgeMode::createTruncateMode()
            : PurgeMode::createDeleteMode()
        ;

        $purger = new OrmPurger($manager, $purgeMode);
        $loader = new PurgerLoader($loader, $purger, $purger);
        $loader = new FileResolverLoader($loader, new KernelFileResolver($kernel));

        return $loader->load($files, $parameters);
    }
}
