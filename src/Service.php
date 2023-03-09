<?php

declare(strict_types=1);

namespace think\admin\install;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Util\Platform;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * 组件插件注册
 * @class Service
 * @package think\admin\install
 */
class Service implements PluginInterface
{
    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {

        // 根应用包
        $package = $this->addServer($composer)->getPackage();

        // 注册安装器
        $composer->getInstallationManager()->addInstaller(new Installer($io, $composer));

        // 读取根应用配置
        $config = json_decode(file_get_contents('composer.json'), true);
        if (empty($config['type']) && empty($config['name'])) {
            method_exists($package, 'setType') && $package->setType('project');
        }

        // 读取项目根参数
        if ($package->getType() === 'project') {

            // 修改项目配置
//          if (empty($pluginCenter)) {
//              $composer->getConfig()->getConfigSource()->addRepository('plugins', [
//                  'url' => $pluginUrl, 'type' => 'composer', 'canonical' => false
//              ]);
//          }

            // 注册自动加载
            $auto = $package->getAutoload();
            if (empty($auto)) $package->setAutoload([
                'psr-0' => ['' => 'extend'], 'psr-4' => ['app\\' => 'app'],
            ]);

            // 写入环境路径
            $this->putServer();

            // 执行插件安装
            $dispatcher = $composer->getEventDispatcher();
            $dispatcher->addListener('post-autoload-dump', function () use ($dispatcher) {

                // 初始化服务配置
                $services = file_exists($file = 'vendor/services.php') ? (array)include($file) : [];
                if (!in_array($service = 'think\\admin\\Library', $services)) {
                    $services = array_unique(array_merge($services, ['think\\migration\\Service', $service]));
                    @file_put_contents($file, '<?php' . PHP_EOL . 'return ' . var_export($services, true) . ';');
                }

                // 调用应用插件及子应用安装指令
                $dispatcher->addListener('PluginScript', '@php think xadmin:publish --migrate');
                $dispatcher->dispatch('PluginScript');
            });
        }
    }

    /**
     * 增加插件服务 ( 需上报应用标识信息 )
     * @param \Composer\Composer $composer
     * @return \Composer\Composer
     */
    private function addServer(Composer $composer): Composer
    {
        $token = base64_encode(json_encode([
            Support::getCpuId(), Support::getMacId(), Support::getSysId(), php_uname()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $manager = $composer->getRepositoryManager();
        $manager->prependRepository($manager->createRepository('composer', [
            'url' => Support::getServer() . 'packages.json',
            'options' => ['http' => ['header' => ["Authorization: Bearer {$token}"]]],
            'canonical' => false,
        ]));
        return $composer;
    }

    /**
     * 写入环境路径
     */
    private function putServer()
    {
        $export = var_export([
            'cpu' => Support::getCpuId(),
            'mac' => Support::getMacId(),
            'uni' => Support::getSysId(),
            'php' => (new PhpExecutableFinder())->find(false) ?: 'php',
            'com' => getenv('COMPOSER_BINARY') ?: (Platform::getEnv('COMPOSER_BINARY') ?: 'composer'),
        ], true);
        $header = '// Automatically Generated At: ' . date('Y-m-d H:i:s') . PHP_EOL . 'declare(strict_types=1);';
        @file_put_contents('vendor/binarys.php', '<?php' . PHP_EOL . $header . PHP_EOL . "return {$export};");
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}