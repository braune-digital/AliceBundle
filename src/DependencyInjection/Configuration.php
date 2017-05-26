<?php

/*
 * This file is part of the Hautelook\AliceBundle package.
 *
 * (c) Baldur Rensch <brensch@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hautelook\AliceBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @private
 *
 * @author Baldur Rensch <brensch@gmail.com>
 * @author Théo FIDRY <theo.fidry@gmail.com>
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('hautelook_alice');

        $rootNode
            ->children()
                ->scalarNode('fixtures_path')
                    ->defaultValue('Resources/fixtures/orm')
                    ->info('Path to which to look for fixtures relative to the bundle path.')
                ->end()
				->booleanNode('use_cache')->defaultValue(false)->end()
				->scalarNode('mysqldump_binary')->defaultValue('mysqldump')->end()
				->scalarNode('mysql_binary')->defaultValue('mysql')->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
