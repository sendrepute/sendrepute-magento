<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if (!str_starts_with((string) realpath($root), '/tmp/sendrepute-magento-installed.')
    || !is_file($root . '/app/etc/env.php')) {
    throw new RuntimeException('Refusing an unrelated Magento instance.');
}
require $root . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
$existing = $om->create(\Magento\User\Model\User::class)->loadByUsername('task600_limited');
if ($existing->getId()) {
    if (!$existing->getRoles()) {
        throw new RuntimeException('Existing restricted admin has no native role.');
    }
    echo "Native Magento restricted admin role/user already exists.\n";
    exit;
}
$role = $om->create(\Magento\Authorization\Model\Role::class);
$role->setRoleName('Task600 dashboard only')
    ->setRoleType(\Magento\Authorization\Model\Acl\Role\Group::ROLE_TYPE)
    ->setUserType(\Magento\Authorization\Model\UserContextInterface::USER_TYPE_ADMIN)
    ->setParentId(0)->setTreeLevel(1)->setSortOrder(0)->save();
$om->create(\Magento\Authorization\Model\Rules::class)
    ->setRoleId((int) $role->getId())->setResourceId('Magento_Backend::dashboard')
    ->setPermission('allow')->save();
$user = $om->create(\Magento\User\Model\User::class);
$user->setUserName('task600_limited')->setFirstName('Local')->setLastName('Restricted')
    ->setEmail('restricted@example.invalid')->setPassword('SyntheticLocalTask600!47')
    ->setRoleId((int) $role->getId())->setIsActive(1)->save();
if (!$user->getId() || !$om->create(\Magento\User\Model\User::class)->load($user->getId())->getRoles()) {
    throw new RuntimeException('Restricted admin role was not assigned.');
}
echo "Native Magento restricted admin role/user created.\n";