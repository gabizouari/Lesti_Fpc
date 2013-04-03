<?php
/**
 * Created by JetBrains PhpStorm.
 * User: gordon
 * Date: 24.10.12
 * Time: 12:26
 * To change this template use File | Settings | File Templates.
 */
class Lesti_Fpc_Model_Observer
{
    const CACHE_TYPE = 'fpc';
    const CUSTOMER_SESSION_REGISTRY_KEY = 'fpc_customer_session';

    protected $_cached = false;
    protected $_html = array();
    protected $_placeholder = array();
    protected $_cache_tags = array();

    public function controllerActionLayoutGenerateBlocksBefore($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive() && !$this->_cached) {
            $key = Mage::helper('fpc')->getKey();
            if($body = $fpc->load($key)) {
                $this->_cached = true;
                $layout = $observer->getEvent()->getLayout();
                $xml = simplexml_load_string($layout->getXmlString(), Lesti_Fpc_Helper_Data::LAYOUT_ELEMENT_CLASS);
                $cleanXml = simplexml_load_string('<layout/>', Lesti_Fpc_Helper_Data::LAYOUT_ELEMENT_CLASS);
                $types = array('block', 'reference', 'action');
                $dynamicBlocks = Mage::helper('fpc/block')->getDynamicBlocks();
                foreach ($dynamicBlocks as $blockName) {
                    foreach ($types as $type) {
                        $xPath = $xml->xpath("//" . $type . "[@name='" . $blockName . "']");
                        foreach ($xPath as $child) {
                            $cleanXml->appendChild($child);
                        }
                    }
                }
                $layout->setXml($cleanXml);
                $layout->generateBlocks();
                $layout = Mage::helper('fpc/block_messages')->initLayoutMessages($layout);
                foreach ($dynamicBlocks as $blockName) {
                    $block = $layout->getBlock($blockName);
                    if ($block) {
                        $this->_placeholder[] = Mage::helper('fpc/block')->getPlaceholderHtml($blockName);
                        $this->_html[] = $block->toHtml();
                    }
                }
                $body = str_replace($this->_placeholder, $this->_html, $body);
                Mage::app()->getResponse()->setBody($body);
                Mage::app()->getResponse()->sendResponse();
                exit;
            }
        }
    }

    public function httpResponseSendBefore($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive() && !$this->_cached) {
            $fullActionName = Mage::helper('fpc')->getFullActionName();
            $cacheableActions = Mage::helper('fpc')->getCacheableActions();
            if (in_array($fullActionName, $cacheableActions)) {
                $fpc->cleanOld();
                $key = Mage::helper('fpc')->getKey();
                $body = $observer->getEvent()->getResponse()->getBody();
                $this->_cache_tags = array_merge(Mage::helper('fpc')->getCacheTags(), $this->_cache_tags);
                $fpc->save($body, $key, $this->_cache_tags);
                $this->_cached = true;
                $body = str_replace($this->_placeholder, $this->_html, $body);
                $observer->getEvent()->getResponse()->setBody($body);
            }
        }
    }

    public function coreBlockAbstractToHtmlAfter($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive() && !$this->_cached) {
            $fullActionName = Mage::helper('fpc')->getFullActionName();
            $block = $observer->getEvent()->getBlock();
            $blockName = $block->getNameInLayout();
            $dynamicBlocks = Mage::helper('fpc/block')->getDynamicBlocks();
            $cacheableActions = Mage::helper('fpc')->getCacheableActions();
            if (in_array($fullActionName, $cacheableActions)) {
                $this->_cache_tags = array_merge(Mage::helper('fpc/block')->getCacheTags($block), $this->_cache_tags);
                if (in_array($blockName, $dynamicBlocks)) {
                    $blockName = $blockName == 'global_messages' ? 'messages' : $blockName;
                    $placeholder = Mage::helper('fpc/block')->getPlaceholderHtml($blockName);
                    $html = $observer->getTransport()->getHtml();
                    $this->_html[] = $html;
                    $this->_placeholder[] = $placeholder;
                    $observer->getTransport()->setHtml($placeholder);
                }
            }
        }
    }

    public function adminhtmlCacheRefreshType($observer)
    {
        if ($observer->getEvent()->getType() == self::CACHE_TYPE) {
            $fpc = $this->_getFpc();
            $fpc->cleanAll();
        }
    }

    public function catalogProductSaveAfter($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive()) {
            $product = $observer->getEvent()->getProduct();
            if ($product->getId()) {
                $fpc->cleanByTag(sha1('product_' . $product->getId()));
            }
        }
    }

    public function catalogCategorySaveAfter($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive()) {
            $category = $observer->getEvent()->getCategory();
            if ($category->getId()) {
                $fpc->cleanByTag(sha1('category_' . $category->getId()));
            }
        }
    }

    public function cmsPageSaveAfter($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive()) {
            $page = $observer->getEvent()->getObject();
            if ($page->getId()) {
                $tags = array(sha1('cms_' . $page->getId()),
                    sha1('cms_' . $page->getIdentifier()));
                $fpc->cleanByTag($tags, Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG);
            }
        }
    }

    public function modelSaveAfter($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive()) {
            $object = $observer->getEvent()->getObject();
            if (get_class($object) == get_class(Mage::getModel('cms/block'))) {
                $fpc->cleanbyTag(sha1('cmsblock_' . $object->getIdentifier()));
            }
        }
    }

    public function rebuildCache($observer)
    {
        $fpc = $this->_getFpc();
        if ($fpc->isActive() && Mage::helper('fpc')->rebuildCache()) {
            $this->_getFpc()->rebuild();
        }
    }

    protected function _getFpc()
    {
        return Mage::getSingleton('fpc/fpc');
    }

}