<?php

namespace Knawat\Dropshipping\Controller\Adminhtml\Dropshipping;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

/**
 * Class Saveimport
 * @package Knawat\Dropshipping\Controller\Adminhtml\Dropshipping
 */
class Saveimport extends \Magento\Backend\App\Action
{

    /**
     * @var \Knawat\Dropshipping\Helper\CommonHelper
     */
    protected $commonHelper;


    /**
     * Saveimport constructor.
     * @param Context $context
     * @param \Knawat\Dropshipping\Helper\CommonHelper $commonHelper
     */
    public function __construct(
        Context $context,
        \Knawat\Dropshipping\Helper\CommonHelper $commonHelper
    ) {
        parent::__construct($context);
        $this->commonHelper = $commonHelper;
    }

    /**
     * save and update import tab's information
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     */
    public function execute()
    {
        $this->commonHelper->runImport();
        // Redirect to import page.
        $this->_redirect('dropshipping/dropshipping/import/');
    }

    /**
     * Check Permission.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Knawat_Dropshipping::saveimport');
    }
}
