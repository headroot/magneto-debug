<?php

/**
 * Class Sheep_Debug_Model_Observer
 *
 * @category Sheep
 * @package  Sheep_Debug
 * @license  Copyright: Pirate Sheep, 2016, All Rights reserved.
 * @link     https://piratesheep.com
 *
 *
 * TODO: clarify stages where request info's data is updated and when should be changed..
 * TODO: what do we generate when saving is not enabled !? Do we use persist data in cache for 30-60 minutes?
 */
class Sheep_Debug_Model_Observer
{
    // This is initialised as soon as app is setup
    protected $canCapture = true;

    public function canCapture()
    {
        return $this->canCapture;
    }

    /**
     * Returns request info model associated to current request.
     *
     * @return Sheep_Debug_Model_RequestInfo
     */
    public function getRequestInfo()
    {
        return Mage::getSingleton('sheep_debug/requestInfo');
    }


    public function startProfiling(Mage_Core_Controller_Request_Http $httpRequest)
    {
        // Register shutdown function
        register_shutdown_function(array($this, 'shutdown'));

        // Now we can properly initialise canCapture based on configuration
        $this->canCapture = Mage::helper('sheep_debug')->canCapture();

        $requestInfo = $this->getRequestInfo();
        $requestInfo->setStoreId(Mage::app()->getStore()->getId());
        $requestInfo->setIp(Mage::helper('core/http')->getRemoteAddr());
        $requestInfo->setRequestPath($httpRequest->getOriginalPathInfo());
        $requestInfo->setDate(date('Y-m-d H:i:s'));
        $requestInfo->initLogging();

        if (Mage::helper('sheep_debug')->canEnableVarienProfiler()) {
            Varien_Profiler::enable();
        }
    }


    /**
     * Executed after response is send. Now is safe to capture properties like response code, request time or peak memory usage.
     *
     * @param $response
     */
    public function completeProfiling(Mage_Core_Controller_Response_Http $response)
    {
        $requestInfo = $this->getRequestInfo();
        $helper = Mage::helper('sheep_debug');

        // update query information
        $requestInfo->initQueries();

        // capture log ranges
        $requestInfo->getLogging()->endRequest();

        // save rendering time
        $requestInfo->setRenderingTime(Sheep_Debug_Model_Block::getTotalRenderingTime());

        // first tentative to save response code
        $requestInfo->getController()->addResponseInfo($response);
        $requestInfo->setPeakMemory($helper->getMemoryUsage());
        $requestInfo->setTime($helper->getCurrentScriptDuration());
        $requestInfo->setTimers(Varien_Profiler::getTimers());
    }


    /**
     * This represents a shutdown callback that allows us to safely save our request info
     */
    public function shutdown()
    {
        $requestInfo = $this->getRequestInfo();
        $helper = Mage::helper('sheep_debug');

        // Last chance to update request profile info
        $requestInfo->setPeakMemory($helper->getMemoryUsage());
        $requestInfo->setTime($helper->getCurrentScriptDuration());
        $requestInfo->setResponseCode(http_response_code());

        // Sets latest queries
        $requestInfo->initQueries();

        $this->saveProfiling();
    }


    /**
     * Saves request info model.
     *
     * @throws Exception
     */
    public function saveProfiling()
    {
        if (!$this->canCapture() || !Mage::helper('sheep_debug')->canPersist()) {
            return;
        }

        $this->getRequestInfo()->save();
    }

    /**
     * Listens to controller_front_init_before event. An event that we can consider the start of the
     * request profiling.
     * @param Varien_Event_Observer $observer
     */
    public function onControllerFrontInitBefore(Varien_Event_Observer $observer)
    {
        /** @var Mage_Core_Controller_Varien_Front $front */
        $front = $observer->getData('front');
        $this->startProfiling($front->getRequest());
    }


    /**
     * Listens to controller_action_predispatch event to prevent undesired access
     * TODO: review access permissions
     *
     * We listen to this event to filter access to actions defined by Debug module.
     * We allow only actions if debug toolbar is on and ip is listed in Developer Client Restrictions
     *
     * @param Varien_Event_Observer $observer
     *
     * @return void
     */
    public function onActionPreDispatch(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        $action = $observer->getData('controller_action');

        // Record action that handled current request
        $this->getRequestInfo()->addControllerAction($action);
    }


    /**
     * Listens to controller_action_layout_generate_blocks_after and records
     * instantiated blocks
     *
     * @param Varien_Event_Observer $observer
     */
    public function onLayoutGenerate(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        /** @var Mage_Core_Model_Layout $layout */
        $layout = $observer->getData('layout');
        $requestInfo = $this->getRequestInfo();

        // Adds block description for all blocks generated by layout
        $layoutBlocks = $layout->getAllBlocks();
        foreach ($layoutBlocks as $block) {
            if ($this->_skipBlock($block)) {
                continue;
            }
            
            $requestInfo->addBlock($block);
        }

        // Add design
        /** @var Mage_Core_Model_Design_Package $design */
        $design = Mage::getSingleton('core/design_package');
        $requestInfo->addLayout($layout, $design);

        // Save profiler information?
        $this->saveProfiling();
    }


    /**
     * Listens to core_block_abstract_to_html_before event and records blocks
     * that are about to being rendered.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onBlockToHtml(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        /* @var $block Mage_Core_Block_Abstract */
        $block = $observer->getData('block');
        if ($this->_skipBlock($block)) {
            return;
        }

        $requestInfo = $this->getRequestInfo();
        try {
            $blockInfo = $requestInfo->getBlock($block->getNameInLayout());
        } catch (Exception $e) {
            // block was not found - lets add it now
            $blockInfo = $requestInfo->addBlock($block);
        }

        $blockInfo->startRendering($block);
    }


    /**
     * Listens to core_block_abstract_to_html_after event and computes time spent in
     * block's _toHtml (rendering time).
     *
     * @param Varien_Event_Observer $observer
     */
    public function onBlockToHtmlAfter(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        /* @var $block Mage_Core_Block_Abstract */
        $block = $observer->getData('block');

        // Don't list blocks from Debug module
        if ($this->_skipBlock($block)) {
            return;
        }

        $blockInfo = $this->getRequestInfo()->getBlock($block->getNameInLayout());
        $blockInfo->completeRendering($block);
    }


    /**
     * Listens to controller_action_postdispatch event and captures route and controller
     * information.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onActionPostDispatch(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        /** @var Mage_Core_Controller_Varien_Action $action */
        $action = $observer->getData('controller_action');

        $this->getRequestInfo()->addControllerAction($action);
    }


    /**
     * Listens to core_collection_abstract_load_before and eav_collection_abstract_load_before events
     * and records loaded collections
     *
     * @param Varien_Event_Observer $observer
     */
    public function onCollectionLoad(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        /** @var Mage_Core_Model_Resource_Db_Collection_Abstract */
        $collection = $observer->getData('collection');
        $this->getRequestInfo()->addCollection($collection);
    }


    /**
     * Listens to model_load_after and records loaded models
     *
     * @param Varien_Event_Observer $observer
     */
    public function onModelLoad(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        $model = $observer->getData('object');
        $this->getRequestInfo()->addModel($model);
    }


    /**
     * Listens to controller_front_send_response_after. This event represents the end of a request.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onControllerFrontSendResponseAfter(Varien_Event_Observer $observer)
    {
        if (!$this->canCapture()) {
            return;
        }

        /** @var Mage_Core_Controller_Varien_Front $front */
        $front = $observer->getData('front');

        $this->completeProfiling($front->getResponse());
    }

    /**
     *
     * TODO: Make this a setting
     *
     * @return bool
     */
    protected function _skipCoreBlocks()
    {
        return false;
    }


    /**
     * Logic that checks if we should ignore this block
     *
     * @param $block Mage_Core_Block_Abstract
     * @return bool
     */
    protected function _skipBlock($block)
    {
        $blockClass = get_class($block);

        if ($this->_skipCoreBlocks() && strpos($blockClass, 'Mage_') === 0) {
            return true;
        }

        // Skip our own blocks
        if (strpos($blockClass, 'Sheep_Debug_Block') === 0) {
            return true;
        }

        return false;
    }

}
