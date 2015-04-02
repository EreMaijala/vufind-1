<?php
/**
 * Module for storing local overrides for Finna.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Module
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/dmj/vf2-proxy
 */
namespace Finna;
use Zend\EventManager\StaticEventManager,
    Zend\ModuleManager\ModuleManager,
    Zend\Mvc\MvcEvent;

/**
 * Module for storing local overrides for Finna.
 *
 * @category VuFind2
 * @package  Module
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/dmj/vf2-proxy
 */
class Module
{
    /**
     * Get module configuration
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Get autoloader configuration
     *
     * @return array
     */
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    /**
     * Initialize the module
     *
     * @param ModuleManager $m Module manager
     *
     * @return void
     */
    public function init(ModuleManager $m)
    {
        $em = StaticEventManager::getInstance();
        $em->attach('*', 'bootstrap', [$this, 'registerBaseUrl'], 100000);
    }

    /**
     * Bootstrap the module
     *
     * @param MvcEvent $e Event
     *
     * @return void
     */
    public function onBootstrap(MvcEvent $e)
    {

    }

    /**
     * Initializes the base url for the application from environment variable
     * @param MvcEvent $e
     */
    public function registerBaseUrl(MvcEvent $e)
    {
        $request = $e->getApplication()->getRequest();
        $baseUrl = $request->getServer('FINNA_BASE_URL');

        if (!empty($baseUrl)) {
            $baseUrl = '/' . trim($baseUrl, '/');
            $router = $e->getApplication()->getServiceManager()->get('Router');
            $router->setBaseUrl($baseUrl);
            $request->setBaseUrl($baseUrl);
        }
    }
}
