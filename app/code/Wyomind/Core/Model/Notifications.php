<?php

/**
 * Copyright © 2017 Wyomind. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Wyomind\Core\Model;

/**
 * License backend Notifications
 */
class Notifications extends \Magento\AdminNotification\Model\System\Message
{

    /**
     * Soap url for the license server
     */
    const SOAP_URL = "https://www.wyomind.com/services/licenses/webservice.soap.php";

    /**
     * Soap uri for the license server
     */
    const SOAP_URI = "https://www.wyomind.com/";

    /**
     * Webservice url for the license server
     */
    const WS_URL = "https://www.wyomind.com/license_activation/?licensemanager=%s&";

    /**
     * @var array
     */
    protected $_values = [];

    /**
     * @var string
     */
    protected $_version = "";

    /**
     * @var array
     */
    protected $_warnings = [];

    /**
     * @var \Wyomind\Core\Helper\Data
     */
    protected $_coreHelper = null;

    /**
     * @var
     */
    protected $_cacheManager = null;

    /**
     * @var \Magento\Framework\Filesystem\Directory\ReadFactory array
     */
    protected $_directoryRead = [];

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $_directoryList = null;

    /**
     * @var boolean
     */
    protected $_refreshCache = false;

    /**
     * @var array
     */
    protected $_messages = [
        "activation_key_warning" => "Your activation key is not yet registered.<br>Go to <a href='%s'>Stores > Configuration > Wyomind > %s</a>.",
        "license_code_warning" => "Your license is not yet activated.<br><a target='_blank' href='%s'>Activate it now !</a>",
        "license_code_updated_warning" => "Your license must be re-activated.<br><a target='_blank' href='%s'>Re-activate it now !</a>",
        "ws_error" => "The Wyomind's license server encountered an error.<br><a target='_blank' href='%s'>Please go to Wyomind license manager</a>",
        "ws_success" => "<b style='color:green'>%s</b>",
        "ws_failure" => "<b style='color:red'>%s</b>",
        "ws_no_allowed" => "Your server doesn't allow remote connections.<br><a target='_blank' href='%s'>Please go to Wyomind license manager</a>",
        "upgrade" => "<u>Extension upgrade from v%s to v%s</u>.<br> Your license must be updated.<br>Please clean all caches and reload this page.",
        "license_warning" => "License Notification"
    ];

    /**
     * @var string
     */
    protected $_magentoVersion = 0;

    /**
     * @var \Wyomind\Core\Logger\Logger
     */
    protected $_logger = null;

    /**
     * @var boolean
     */
    protected $_logEnabled = false;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $_auth = null;

    /**
     * Class constructor
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Module\ModuleList $moduleList
     * @param \Magento\Framework\App\Config\MutableScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\Session\SessionManagerInterface $session
     * @param \Wyomind\Core\Helper\Data $coreHelper
     * @param \Magento\Framework\Filesystem\Directory\ReadFactory $directoryRead
     * @param \Magento\Framework\Filesystem\File\ReadFactory $fileRead
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Wyomind\Core\Logger\Logger $logger
     * @param \Magento\Backend\Model\Auth\Session $auth
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
    \Magento\Framework\Model\Context $context,
            \Magento\Framework\Registry $registry,
            \Magento\Framework\Module\ModuleList $moduleList,
            \Magento\Framework\App\Config\MutableScopeConfigInterface $scopeConfig,
            \Magento\Framework\UrlInterface $urlBuilder,
            \Magento\Framework\Session\SessionManagerInterface $session,
            \Wyomind\Core\Helper\Data $coreHelper,
            \Magento\Framework\Filesystem\Directory\ReadFactory $directoryRead,
            \Magento\Framework\Filesystem\File\ReadFactory $fileRead,
            \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
            \Wyomind\Core\Logger\Logger $logger,
            \Magento\Backend\Model\Auth\Session $auth,
            \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
            \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
            array $data = []
    )
    {

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);

        $this->_magentoVersion = $coreHelper->getMagentoVersion();

        $this->_moduleList = $moduleList;
        $this->_scopeConfig = $scopeConfig;
        $this->_urlBuilder = $urlBuilder;
        $this->_cacheManager = $context->getCacheManager();
        $this->_session = $session;
        $this->_coreHelper = $coreHelper;
        $this->_logEnabled = $this->_coreHelper->isLogEnabled();
        $this->_logger = $logger;
        $this->_auth = $auth;
        $root = $directoryList->getPath(\Magento\Framework\App\Filesystem\DirectoryList::ROOT);
        if (file_exists($root . "/vendor/wyomind/")) {
            $this->_directoryRead[] = $directoryRead->create($root . "/vendor/wyomind/");
        }
        if (file_exists($root . "/app/code/Wyomind/")) {
            $this->_directoryRead[] = $directoryRead->create($root . "/app/code/Wyomind/");
        }
        $this->_httpRead = $fileRead;
        $this->_directoryList = $directoryList;

        $this->_version = $this->_moduleList->getOne("Wyomind_Core")['setup_version'];

        $this->_refreshCache = false;

        $this->getValues();
        foreach ($this->_values as $ext) {
            $this->checkActivation($ext);
        }

        if ($this->_refreshCache) {
            $this->_cacheManager->clean(['config']);
        }
    }

    /**
     * Add a line in the log
     * @param string $msg
     */
    public function notice($msg)
    {
        if ($this->_logEnabled) {
            $this->_logger->notice($msg);
        }
    }

    /**
     * Retrieve extensions information
     */
    public function getValues()
    {
        $dir = ".";
        $ret = [];
        foreach ($this->_directoryRead as $directoryRead) {
            foreach ($directoryRead->read($dir) as $file) {
                if ($file !== "./Core" && $file !== "./wyomind-core") {
                    if ($directoryRead->isDirectory($file) && $file != "." && $file != "..") {
                        if ($directoryRead->isFile($file . "/etc/config.xml")) {
                            $namespace = strtolower(str_replace("./", "", $file));
                            if ($this->_coreHelper->moduleIsEnabled("Wyomind_" . str_replace("./", "", $file))) { // disabled ?
                                $label = $this->_coreHelper->getStoreConfig($namespace . "/license/extension_label");
                                $version = $this->_coreHelper->getStoreConfig($namespace . "/license/extension_version");
                                $ret[] = ["label" => $label, "value" => $file, "version" => $version];
                            }
                        }
                    }
                }
            }
        }
        $this->_values = $ret;
    }

    /**
     * Transform XML to array
     * @param string $xml
     * @return array
     */
    public function XML2Array($xml)
    {
        $newArray = [];
        $array = (array) $xml;
        foreach ($array as $key => $value) {
            $value = (array) $value;
            if (isset($value [0])) {
                $newArray [$key] = trim($value [0]);
            } else {
                $newArray [$key] = $this->XML2Array($value, true);
            }
        }
        return $newArray;
    }

    /**
     * Add a license warning
     * @param string $name
     * @param string $type
     * @param array $vars
     */
    protected function addWarning(
    $name,
            $type,
            $vars = []
    )
    {

        if ($type) {
            $output = $this->sprintfArray($this->_messages[$type], $vars);
        } else {
            $output = implode(" " . $vars);
        }
        $output = "<b> Wyomind " . $name . "</b> <br> " . $output . "";

        $this->_warnings[] = $output;
    }

    /**
     * Print array
     * @param string $format
     * @param array  $arr
     * @return string
     */
    protected function sprintfArray(
    $format,
            $arr
    )
    {
        return call_user_func_array("sprintf", array_merge((array) $format, $arr));
    }

    /**
     * Check if extension can be registered
     * @param array $extension
     */
    protected function checkActivation($extension)
    {

        $wsUrl = sprintf(self::WS_URL, $this->_version);

        $ext = "" . strtolower(str_replace("./", "", $extension["value"]));

        
        
        
        $licensingMethod = $this->_coreHelper->getDefaultConfig($ext . "/license/get_online_license");
        $currentVersion = $extension["version"];

        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $encryptor = $objectManager->get("Magento\Framework\Encryption\EncryptorInterface");
        $configResourceModel = $objectManager->get("Wyomind\Core\Model\ResourceModel\Config");
        
        $registeredVersion = $configResourceModel->getDefaultValueByPath($ext . "/license/version");
        
        if ($registeredVersion == "") {
            $registeredVersion = $this->_coreHelper->getDefaultConfig($ext . "/license/version");
        }
        
        $activationKey = $encryptor->decrypt($configResourceModel->getDefaultValueByPath($ext . "/license/activation_key"));
        
        if ($activationKey == "") {
            $activationKey = $this->_coreHelper->getDefaultConfigUncrypted($ext . "/license/activation_key");
        }
        
        $licenseCode = $encryptor->decrypt($configResourceModel->getDefaultValueByPath($ext . "/license/activation_code"));
        if ($licenseCode == "") {
            $licenseCode = $this->_coreHelper->getDefaultConfigUncrypted($ext . "/license/activation_code");
        }
        
        $domain = str_replace("{{unsecure_base_url}}", $configResourceModel->getDefaultValueByPath("web/unsecure/base_url"), $configResourceModel->getDefaultValueByPath("web/secure/base_url"));
        if ($domain == "") {
            $domain = str_replace("{{unsecure_base_url}}", $this->_coreHelper->getDefaultConfig("web/unsecure/base_url"), $this->_coreHelper->getDefaultConfig("web/secure/base_url"));
        }


        $wsParam = "&rv=" . $registeredVersion . "&cv=" . $currentVersion . "&namespace=" . $ext . "&activation_key=" . $activationKey . "&domain=" . $domain . "&magento=" . $this->_magentoVersion;
        $soapParams = [
            "method" => "get",
            "rv" => $registeredVersion,
            "cv" => $currentVersion,
            "namespace" => $ext,
            "activation_key" => $activationKey,
            "domain" => $domain,
            "magento" => $this->_magentoVersion,
            "licensemanager" => $this->_version
        ];

        // licence deleted because wrong ak or ac
        if ($registeredVersion != "" && $registeredVersion != $currentVersion && $licenseCode) { // Extension upgrade
            $this->notice("------------------------------------------");
            $this->notice("Checking registration of the license");
            $this->notice("Upgrade " . $extension['label'] . " from " . $registeredVersion . " to " . $currentVersion);
            $this->notice("Activation key: " . $activationKey);
            if ($this->_auth->getUser() != null) {
                $this->notice("User: " . $this->_auth->getUser()->getUsername());
            }
            $this->_coreHelper->setDefaultConfig($ext . "/license/activation_code", "");
            $this->_coreHelper->setDefaultConfig($ext . "/license/version", $currentVersion);
            $this->addWarning($extension["label"], "upgrade", [$registeredVersion, $currentVersion]);
            $this->_session->setData("update_" . $extension["value"], "true");
            $this->_refreshCache = true;
        } elseif (!$activationKey) { // no activation key not yet registered
            $this->notice("------------------------------------------");
            $this->notice("Checking registration of the license");
            $this->notice("Extension " . $extension['label'] . " not registered yet");
            $this->notice("Activation key: " . $activationKey);
            if ($this->_auth->getUser() != null) {
                $this->notice("User: " . $this->_auth->getUser()->getUsername());
            }
            $this->_coreHelper->setDefaultConfig($ext . "/license/activation_code", "");
            $this->addWarning($extension["label"], "activation_key_warning", [$this->_urlBuilder->getUrl("adminhtml/system_config/edit/section/" . $ext . "/"), ($extension["label"])]);
            $this->_refreshCache = true;
        } elseif ($activationKey && (!$licenseCode || empty($licenseCode)) && !$licensingMethod) { // not yet activated --> manual activation
            $this->notice("------------------------------------------");
            $this->notice("Checking registration of the license");
            $this->notice("Extension " . $extension['label'] . " not registered yet (manual)");
            $this->notice("Activation key: " . $activationKey);
            if ($this->_auth->getUser() != null) {
                $this->notice("User: " . $this->_auth->getUser()->getUsername());
            }
            $this->_coreHelper->setDefaultConfig($ext . "/license/activation_code", "");
            if ($this->_session->getData("update_" . $extension["value"]) != "true") {
                $this->addWarning($extension["label"], "license_code_warning", [$wsUrl . "method=post" . $wsParam]);
            } else {
                $this->addWarning($extension["label"], "license_code_updated_warning", [$wsUrl . "method=post" . $wsParam]);
            }
            $this->_refreshCache = true;
        } elseif ($activationKey && (!$licenseCode || empty($licenseCode)) && $licensingMethod) { // not yet activated --> automatic activation
            $this->notice("------------------------------------------");
            $this->notice("Checking module license registration");
            $this->notice("Automatic registration for " . $extension['label'] . "");
            $this->notice("Activation key: " . $activationKey);
            if ($this->_auth->getUser() != null) {
                $this->notice("User: " . $this->_auth->getUser()->getUsername());
            }

            try {
                $options = ['location' => self::SOAP_URL, 'uri' => self::SOAP_URI];
                if (!class_exists("\SoapClient")) {
                    throw new \Exception();
                }
                $api = new \SoapClient(null, $options);
                $ws = $api->checkActivation($soapParams);
                $wsResult = json_decode($ws);
                switch ($wsResult->status) {
                    case "success":
                        $this->notice("The license has been registered.");
                        $this->notice("License code: " . $wsResult->activation);
                        $this->notice("Version: " . $wsResult->version);
                        $this->addWarning($extension["label"], "ws_success", [$wsResult->message], true);
                        $this->_coreHelper->setDefaultConfig($ext . "/license/version", $wsResult->version);
                        $this->_coreHelper->setDefaultConfigCrypted($ext . "/license/activation_code", $wsResult->activation);
                        $this->_refreshCache = true;
                        break;
                    case "error":
                        $this->notice("Version: " . $wsResult->version);
                        $this->notice("The license cannot be registered: " . $wsResult->message);
                        $this->addWarning($extension["label"], "ws_failure", [$wsResult->message]);
                        $this->_coreHelper->setDefaultConfig($ext . "/license/activation_code", "");
                        $this->_refreshCache = true;
                        break;
                    default:
                        $this->notice("Version: " . $wsResult->version);
                        $this->notice("The license cannot be registered (other error): " . $wsUrl . "method=post" . $wsParam);
                        $this->addWarning($extension["label"], "ws_error", [$wsUrl . "method=post" . $wsParam]);
                        $this->_coreHelper->setDefaultConfig($ext . "/license/activation_code", "");
                        $this->_coreHelper->setDefaultConfig($ext . "/license/get_online_license", "0");
                        $this->_refreshCache = true;
                        break;
                }
            } catch (\Exception $e) {
                $this->notice("Soap request not allowed. Switching to manual activation");
                $this->addWarning($extension["label"], "ws_no_allowed", [$wsUrl . "method=post" . $wsParam]);
                $this->_coreHelper->setDefaultConfig($ext . "/license/activation_code", "");
                $this->_coreHelper->setDefaultConfig($ext . "/license/get_online_license", "0");
                $this->_refreshCache = true;
            }
        }
    }

    /**
     * @return string
     */
    public function getIdentity()
    {
        return md5($this->getText());
    }

    /**
     * @return int
     */
    public function getSeverity()
    {
        return self::SEVERITY_CRITICAL;
    }

    /**
     * @return string
     */
    public function getText()
    {
        $html = null;
        $count = count($this->_warnings);
        for ($i = 0; $i < $count; $i++) {
            $html.="<div style='padding-bottom:5px;" . (($i != 0) ? "margin-top:5px;" : "") . "" . (($i < $count - 1) ? "border-bottom:1px solid gray;" : "") . "'>" . $this->_warnings[$i] . "</div>";
        }

        return $html;
    }

    /**
     * @return boolean
     */
    public function isDisplayed()
    {
        return count($this->_warnings) > 0;
    }

}
