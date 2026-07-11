<?php

declare(strict_types=1);

namespace BugBoard\Symfony;

use BugBoard\Client;
use BugBoard\ClientBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony integration.
 *
 * Registers the shared BugBoard client as an autowirable service. Enable it
 * in `config/bundles.php` and configure it in `config/packages/bugboard.yaml`:
 *
 *     bugboard:
 *         key_id: '%env(BUGBOARD_KEY_ID)%'
 *         signing_secret: '%env(BUGBOARD_SIGNING_SECRET)%'
 *
 * Option names and defaults mirror the shared SDK specification.
 */
final class BugBoardBundle extends AbstractBundle
{
    protected string $extensionAlias = 'bugboard';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('key_id')->defaultNull()->info('Public key id (bbk_…) for HMAC auth.')->end()
            ->scalarNode('signing_secret')->defaultNull()->info('Signing secret (bb_sec_…); never transmitted.')->end()
            ->scalarNode('api_key')->defaultNull()->info('Publishable key (bb_pub_…) — browser/mobile keys; rarely right for PHP.')->end()
            ->scalarNode('encryption_public_key')->defaultNull()->info('Base64 X25519 public key; when set, payloads are encrypted in transit.')->end()
            ->scalarNode('encryption_key_id')->defaultNull()->info('Encryption key id (bbek_…) echoed in the envelope.')->end()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->booleanNode('capture_location')->defaultTrue()->info("Auto-capture the caller's file/line as file_name / line_number.")->end()
            ->scalarNode('environment')->defaultNull()->info('Added to every card as tag env:<value>.')->end()
            ->scalarNode('release')->defaultNull()->info('Added to every card as tag release:<value>.')->end()
            ->arrayNode('default_tags')->scalarPrototype()->end()->end()
            ->floatNode('sample_rate')->defaultValue(1.0)->end()
            ->integerNode('max_queue_size')->defaultValue(100)->end()
            ->integerNode('timeout_ms')->defaultValue(5000)->end()
            ->integerNode('max_retries')->defaultValue(3)->end()
            ->booleanNode('debug')->defaultFalse()->end()
            ->booleanNode('log_locally')->defaultFalse()->info('Log each report locally instead of sending it (local debugging / dry run).')->end()
            ->booleanNode('hide_api_response')->defaultTrue()->info("Ask the server to omit the card from its response, so a report isn't echoed back.")->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set('bugboard.client', Client::class)
            ->factory([ClientBuilder::class, 'createFromArray'])
            ->args([$config])
            ->public()
            ->alias(Client::class, 'bugboard.client')
            ->public();
    }
}
