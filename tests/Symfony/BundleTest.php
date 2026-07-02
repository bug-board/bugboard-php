<?php

declare(strict_types=1);

namespace BugBoard\Tests\Symfony;

use BugBoard\Client;
use BugBoard\Symfony\BugBoardBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleTest extends TestCase
{
    private function containerFor(array $bundleConfig): ContainerBuilder
    {
        $builder = new ContainerBuilder;
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new BugBoardBundle;
        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);
        $extension->load([$bundleConfig], $builder);

        return $builder;
    }

    public function test_it_registers_an_autowirable_client_service(): void
    {
        $builder = $this->containerFor(['key_id' => 'bbk_test', 'signing_secret' => 'bb_sec_test']);

        $this->assertTrue($builder->hasDefinition('bugboard.client'));
        $this->assertTrue($builder->hasAlias(Client::class));

        $builder->compile();

        $client = $builder->get('bugboard.client');
        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame($client, $builder->get(Client::class));
    }

    public function test_the_extension_alias_is_bugboard(): void
    {
        $extension = (new BugBoardBundle)->getContainerExtension();

        $this->assertNotNull($extension);
        $this->assertSame('bugboard', $extension->getAlias());
    }

    public function test_config_defaults_mirror_the_shared_spec(): void
    {
        $builder = $this->containerFor([]);
        $definition = $builder->getDefinition('bugboard.client');

        /** @var array<string, mixed> $options */
        $options = $definition->getArgument(0);

        $this->assertTrue($options['enabled']);
        $this->assertSame(1.0, $options['sample_rate']);
        $this->assertSame(100, $options['max_queue_size']);
        $this->assertSame(5000, $options['timeout_ms']);
        $this->assertSame(3, $options['max_retries']);
        $this->assertFalse($options['debug']);
    }
}
