<?php

/*
 * This file is part of the RollerworksNavigationBundle package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Bundle\NavigationBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 */
class NavigationExtension extends Extension
{
    /**
     * @var \Symfony\Component\Config\Definition\NodeInterface
     */
    private static $configTree;

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration($this->getAlias());
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerMenus($config['menus'], $container);
        $this->registerBreadcrumbs($config['breadcrumbs'], $container);

        $container->compile();
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'rollerworks_navigation';
    }

    /**
     * @param array            $menus
     * @param ContainerBuilder $container
     */
    private function registerMenus(array $menus, ContainerBuilder $container)
    {
        foreach ($menus as $name => $menu) {

            $menu['options'] = $this->resolveParameters($menu['options']);
            $menu = array_merge($menu['options'], $menu);

            $def = $this->createMenuItem('root', $this->resolveParameters($menu));
            $def->setTags(array('knp_menu.menu' => array(array('alias' => $name))));
            $this->buildMenuDefinition($def, $menu['items']);

            $container->setDefinition('rollerworks_navigation.menu.'.$name, $def);
        }
    }

    /**
     * @param array            $breadcrumbs
     * @param ContainerBuilder $container
     *
     * @throws \RuntimeException
     */
    private function registerBreadcrumbs(array $breadcrumbs, ContainerBuilder $container)
    {
        /** @var Definition[] $finalBreadcrumbs */
        $finalBreadcrumbs = array();

        foreach ($breadcrumbs as $name => $breadcrumb) {
            // Basically what we do is pass trough all the parents
            // And keep track of them, we then reverse them, and loop trough the list
            $methods = array();

            $breadcrumbName = $name;
            $loaded = array();

            while (null !== $breadcrumb) {
                $child = $this->createMenuItemDefinition($name, $breadcrumb);
                $loaded[$name] = true;

                if (is_array($child)) {
                    unset($child['parent']);

                    $methods[] = array('addChild', array($name, $child));
                } else {
                    $methods[] = array('addChild', array($child));
                }

                if (null !== $breadcrumb['parent']) {
                    if (!isset($breadcrumbs[$breadcrumb['parent']])) {
                        throw new \RuntimeException(sprintf('Parent "%s" of breadcrumb "%s" is not registered.', $breadcrumb['parent'], $name));
                    }

                    if (isset($loaded[$breadcrumb['parent']])) {
                        throw new \RuntimeException(sprintf('Circular reference detected with parent of breadcrumb "%s", path: "%s".', $name, implode(' -> ', array_keys($loaded))));
                    }

                    $name = $breadcrumb['parent'];
                    $breadcrumb = $breadcrumbs[$breadcrumb['parent']];

                    continue;
                }

                break;
            }

            // reverse to make the actual child last
            $methods = array_reverse($methods);

            unset($breadcrumb['parent']);

            $finalBreadcrumbs[$breadcrumbName] = $this->createMenuItem('root');
            $finalBreadcrumbs[$breadcrumbName]->setTags(array('knp_menu.menu' => array(array('alias' => $breadcrumbName))));
            $finalBreadcrumbs[$breadcrumbName]->setMethodCalls($methods);
            $container->setDefinition('rollerworks_navigation.breadcrumbs.'.$breadcrumbName, $finalBreadcrumbs[$breadcrumbName]);
        }
    }

    /**
     * @param string $name
     * @param array  $options
     *
     * @return Definition
     */
    private function createMenuItem($name, $options = array())
    {
        unset($options['children']);

        $def = new Definition('Knp\Menu\MenuFactory');
        $this->setFactoryService($def, 'knp_menu.factory', 'createItem');
        $def->setArguments(array($name, $options));

        return $def;
    }

    /**
     * @param string $name
     * @param array  $item
     *
     * @return Definition|array
     */
    private function createMenuItemDefinition($name, array $item)
    {
        if (isset($item['route']['parameters'])) {
            $item['routeParameters'] = $this->resolveParameters($item['route']['parameters']);
            $item['routeAbsolute'] = $this->resolveParameters($item['route']['absolute']);
            $item['route'] = $this->resolveParameters($item['route']['name']);
        }

        $item['options'] = $this->resolveParameters($item['options']);
        $item = array_merge($item['options'], $item);

        unset($item['options']);

        if (!empty($item['service'])) {
            if (empty($item['service']['method'])) {
                return new Reference($item['service']['id']);
            }

            $definition = new Definition('stdClass');
            $this->setFactoryService($definition, $item['service']['id'], $item['service']['method']);

            if (isset($item['service']['parameters'])) {
                $parameters = $this->resolveParameters($item['service']['parameters']);

                if (!is_array($parameters)) {
                    $parameters = array($parameters);
                }

                $definition->setArguments($parameters);
            }

            return $definition;
        }

        if (!empty($item['expression'])) {
            return new Expression($item['expression']);
        }

        if (!empty($item['children'])) {
            $childItems = $item['children'];

            // Don't pass the items to the factory
            unset($item['children'], $item['expression']);

            $definition = $this->createMenuItem($name, $item);
            $this->buildMenuDefinition($definition, $childItems);

            return $definition;
        }

        unset($item['children'], $item['expression']);

        return $item;
    }

    /**
     * @param Definition $definition
     * @param array      $items
     */
    private function buildMenuDefinition(Definition $definition, array $items)
    {
        foreach ($items as $name => $item) {
            $item = $this->validateMenuItemConfig($item);
            $child = $this->createMenuItemDefinition($name, $item);

            if (is_array($child)) {
                $definition->addMethodCall('addChild', array($name, $child));
            } else {
                $definition->addMethodCall('addChild', array($child));
            }
        }
    }

    /**
     * @param array $configs
     *
     * @return array
     */
    private function validateMenuItemConfig(array $configs)
    {
        // Keep it static to prevent to many objects
        if (!self::$configTree) {
            $configTree = new TreeBuilder();
            $node = $configTree->root('item');

            $configuration = new Configuration($this->getAlias());
            $configuration->addItemConfig($node);

            self::$configTree = $configTree;
        }

        $processor = new Processor();

        return $processor->process(self::$configTree->buildTree(), array($configs));
    }

    /**
     * Resolves parameters.
     *
     * @param string $value
     *
     * @return mixed
     */
    private function resolveParameters($value)
    {
        if (is_array($value)) {
            $value = array_map(array($this, 'resolveParameters'), $value);
        } elseif (is_string($value) && 0 === strpos($value, '@')) {
            if ('@' === substr($value, 1, 1)) {
                return substr($value, 1);
            }

            return new Expression(substr($value, 1));
        }

        return $value;
    }

    private function setFactoryService(Definition $definition, $serviceId, $method)
    {
        if (method_exists($definition, 'setFactory')) {
            $definition->setFactory(array(new Reference($serviceId), $method));
        } else {
            $definition->setFactoryService($serviceId);
            $definition->setFactoryMethod($method);
        }
    }
}
