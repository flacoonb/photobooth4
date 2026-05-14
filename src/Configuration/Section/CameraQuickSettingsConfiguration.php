<?php

namespace Photobooth\Configuration\Section;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

final class CameraQuickSettingsConfiguration
{
    public static function getNode(): NodeDefinition
    {
        return (new TreeBuilder('camera_quicksettings'))->getRootNode()->addDefaultsIfNotSet()
            ->ignoreExtraKeys()
            ->children()
                ->booleanNode('enabled')->defaultValue(false)->end()
                ->scalarNode('pin')
                    ->defaultNull()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(function (string $value): ?string {
                            return strlen(trim($value)) === 0 ? null : $value;
                        })
                        ->end()
                    ->end()
                ->enumNode('position')
                    ->values(['top-left', 'top-right', 'bottom-left', 'bottom-right'])
                    ->defaultValue('bottom-left')
                    ->end()
                ->booleanNode('pause_go2rtc')->defaultValue(true)->end()
                ->arrayNode('settings')
                    ->prototype('enum')
                        ->values(['iso', 'aperture', 'shutterspeed', 'whitebalance'])
                        ->end()
                    ->defaultValue(['iso', 'aperture', 'shutterspeed', 'whitebalance'])
                    ->end()
            ->end();
    }
}
