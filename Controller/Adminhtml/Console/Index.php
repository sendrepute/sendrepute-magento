<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Controller\Adminhtml\Console;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Text;
use SendRepute\MailAdapter\Model\ConsoleGateway;
use SendRepute\MailAdapter\ServiceConsole\CurlTransport;
use SendRepute\MailAdapter\ServiceConsole\FileLockStore;

/**
 * Admin service console. Authorization: ACL resource
 * SendRepute_MailAdapter::console (ADMIN_RESOURCE, enforced by Backend\App\Action).
 * CSRF: the admin form key, verified explicitly on every POST in addition to
 * the backend's own secret-key URL protection.
 */
class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SendRepute_MailAdapter::console';

    public function __construct(
        Context $context,
        private DirectoryList $directoryList,
        private DeploymentConfig $deploymentConfig,
        private FormKey $formKey
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $token = getenv('SENDREPUTE_API_TOKEN');
        $token = is_string($token) ? $token : '';
        $key = (string) $this->deploymentConfig->get('crypt/key');
        $user = $this->_auth->getUser();
        $gateway = new ConsoleGateway(
            new CurlTransport(ConsoleGateway::API_BASE, $token),
            new FileLockStore($this->directoryList->getPath(DirectoryList::VAR_DIR) . '/sendrepute-console-locks'),
            hash_hmac('sha256', 'sendrepute-console|' . $token, $key . '|magento-console'),
            'magento-' . ($user ? (int) $user->getId() : 0)
        );
        $isPost = $request->isPost();
        $post = $isPost ? (array) $request->getPostValue() : [];
        $url = $this->getUrl('sendrepute/console/index');
        $result = $gateway->run(
            $isPost,
            $isPost && $this->_formKeyValidator->validate($request),
            $post,
            (string) $request->getParam('tab', 'account'),
            $url,
            $this->formKey->getFormKey(),
            fn (string $t): string => $this->getUrl('sendrepute/console/index', ['tab' => $t])
        );
        if (isset($result['file'])) {
            $raw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            foreach ($result['headers'] as $name => $value) {
                $raw->setHeader($name, $value, true);
            }
            $raw->setContents($result['file'][2]);
            return $raw;
        }
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('SendRepute_MailAdapter::console');
        $page->getConfig()->getTitle()->prepend(__('SendRepute Service Console'));
        $block = $page->getLayout()->createBlock(Text::class, 'sendrepute.console');
        $block->setText($result['html']);
        $page->getLayout()->setChild('content', 'sendrepute.console', 'sendrepute_console');
        return $page;
    }
}
