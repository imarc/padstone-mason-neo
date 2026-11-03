<?php

namespace imarc\padstonemasonneo;

use Craft;
use craft\base\Plugin as BasePlugin;
use imarc\padstonemason\Plugin as Mason;
use imarc\padstonemason\services\structure\StructureHelpers;
use imarc\padstonemasonneo\services\NeoStructureAdapter;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    private static bool $adapterRegistered = false;

    public function init(): void
    {
        parent::init();

        // Mason may load after this plugin; register once both are available.
        Craft::$app->onInit(function() {
            $this->registerAdapter();
        });
    }

    private function registerAdapter(): void
    {
        if (self::$adapterRegistered) {
            return;
        }

        $mason = Mason::getInstance();
        if (!$mason) {
            Craft::warning('Padstone Mason is not installed; Neo structure adapter was not registered.', __METHOD__);

            return;
        }

        self::$adapterRegistered = true;
        $mason->structureAdapters->register(new NeoStructureAdapter(new StructureHelpers()));
        Craft::info('Registered Neo structure adapter for Padstone Mason.', __METHOD__);
    }
}
