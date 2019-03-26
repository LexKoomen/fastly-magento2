<?php
/**
 * Fastly CDN for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Fastly CDN for Magento End User License Agreement
 * that is bundled with this package in the file LICENSE_FASTLY_CDN.txt.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Fastly CDN to newer
 * versions in the future. If you wish to customize this module for your
 * needs please refer to http://www.magento.com for more information.
 *
 * @category    Fastly
 * @package     Fastly_Cdn
 * @copyright   Copyright (c) 2016 Fastly, Inc. (http://www.fastly.com)
 * @license     BSD, see LICENSE_FASTLY_CDN.txt
 */
namespace Fastly\Cdn\Controller\Adminhtml\FastlyCdn\Maintenance;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Fastly\Cdn\Model\Config;
use Fastly\Cdn\Model\Api;
use Fastly\Cdn\Helper\Vcl;

/**
 * Class ToggleSuSetting
 * @package Fastly\Cdn\Controller\Adminhtml\FastlyCdn\Maintenance
 */
class ToggleSuSetting extends Action
{
    /**
     * @var Http
     */
    private $request;
    /**
     * @var JsonFactory
     */
    private $resultJson;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var Api
     */
    private $api;
    /**
     * @var Vcl
     */
    private $vcl;

    /**
     * ToggleSuSetting constructor.
     * @param Context $context
     * @param Http $request
     * @param JsonFactory $resultJsonFactory
     * @param Config $config
     * @param Api $api
     * @param Vcl $vcl
     */
    public function __construct(
        Context $context,
        Http $request,
        JsonFactory $resultJsonFactory,
        Config $config,
        Api $api,
        Vcl $vcl
    ) {
        $this->request = $request;
        $this->resultJson = $resultJsonFactory;
        $this->config = $config;
        $this->api = $api;
        $this->vcl = $vcl;

        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\Result\Json|\Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJson->create();
        try {
            $service = $this->api->checkServiceDetails();
            $currActiveVersion = $this->vcl->getCurrentVersion($service->versions);

            $dictionaryName = Config::CONFIG_DICTIONARY_NAME;
            $dictionary = $this->api->getSingleDictionary($currActiveVersion, $dictionaryName);

            if (!$dictionary) {
                return $result->setData([
                    'status'    => false,
                    'msg'       => 'The required dictionary container does not exist'
                ]);
            }

            $dictionaryItems = $this->api->dictionaryItemsList($dictionary->id);

            if (!$dictionaryItems) {
                $this->api->upsertDictionaryItem(
                    $dictionary->id,
                    Config::CONFIG_DICTIONARY_KEY,
                    1
                );
                $this->sendWebHook('*Super Users have been turned ON*');
            } else {
                foreach ($dictionaryItems as $item) {
                    if ($item->item_key == Config::CONFIG_DICTIONARY_KEY && $item->item_value == 1) {
                        $this->api->upsertDictionaryItem(
                            $dictionary->id,
                            Config::CONFIG_DICTIONARY_KEY,
                            0
                        );
                        $this->sendWebHook('*Super Users have been turned OFF*');
                    } elseif ($item->item_key == Config::CONFIG_DICTIONARY_KEY && $item->item_value == 0) {
                        $this->api->upsertDictionaryItem(
                            $dictionary->id,
                            Config::CONFIG_DICTIONARY_KEY,
                            1
                        );
                        $this->sendWebHook('*Super Users have been turned ON*');
                    }
                }
            }

            return $result->setData([
                'status' => true
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'status'    => false,
                'msg'       => $e->getMessage()
            ]);
        }
    }

    private function sendWebHook($message)
    {
        if ($this->config->areWebHooksEnabled() && $this->config->canPublishConfigChanges()) {
            $this->api->sendWebHook($message);
        }
    }
}
