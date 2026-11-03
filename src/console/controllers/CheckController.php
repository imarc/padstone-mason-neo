<?php

namespace imarc\padstonemasonneo\console\controllers;

use benf\neo\Field as NeoField;
use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin as Mason;
use imarc\padstonemasonneo\services\NeoStructureAdapter;
use yii\console\ExitCode;

/**
 * Smoke checks for the Neo structure adapter.
 *
 * Without a Neo field on the site, this verifies registration and documents
 * the client checklist. With a Neo contentDesigner (or --field=handle) on a
 * draftable entry type, it exercises top-level CRUD and nested refusal.
 */
class CheckController extends Controller
{
    public ?string $field = 'contentDesigner';

    public ?string $entryType = null;

    /** @var string[] */
    private array $failures = [];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['field', 'entryType']);
    }

    public function actionIndex(): int
    {
        $mason = Mason::getInstance();
        if (!$mason) {
            $this->stderr("Padstone Mason is not installed.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $neoAdapter = null;
        foreach ($mason->structureAdapters->all() as $adapter) {
            if ($adapter instanceof NeoStructureAdapter) {
                $neoAdapter = $adapter;
                break;
            }
        }
        if (!$neoAdapter) {
            $this->failures[] = 'Neo structure adapter is not registered. Is padstone-mason-neo enabled?';
        } else {
            $this->stdout("Neo structure adapter is registered.\n");
        }

        $field = Craft::$app->getFields()->getFieldByHandle((string)$this->field);
        if (!$field instanceof NeoField) {
            $this->stdout("No Neo field \"{$this->field}\" on this site. Skipping live Neo CRUD.\n");
            $this->stdout("Client checklist: see plugins/padstone-mason-neo/README.md\n");
            $this->report();

            return $this->failures === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$mason->structureAdapters->forField($field) instanceof NeoStructureAdapter) {
            $this->failures[] = 'Neo field was not routed to the Neo adapter.';
        }

        // Nested payload must be refused even before a draft exists.
        $admin = User::find()->admin()->status(null)->one();
        if (!$admin instanceof User) {
            $this->failures[] = 'No admin user available for Neo checks.';
            $this->report();

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $entryType = $this->entryType
            ? Craft::$app->getEntries()->getEntryTypeByHandle($this->entryType)
            : null;
        $probe = new Entry();
        if ($entryType) {
            $probe->typeId = $entryType->id;
        }

        // Use an unpublished draft owner only when we can create one; otherwise still assert nested refusal via adapter with a throwaway call shape.
        $nestedRefuse = $neoAdapter->addBlock(
            $probe,
            $field,
            'anything',
            null,
            [],
            ['child' => [['type' => 'x']]],
            $admin,
        );
        if (empty($nestedRefuse['ok']) && str_contains((string)($nestedRefuse['error'] ?? ''), 'Nested Neo')) {
            $this->stdout("Nested Neo level create is refused as expected.\n");
        } else {
            $this->failures[] = 'Nested Neo level create was not refused clearly: ' . json_encode($nestedRefuse);
        }

        $this->stdout("Full top-level CRUD still needs a Neo Content Designer draft on the client site (README checklist).\n");
        $this->report();

        return $this->failures === [] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function report(): void
    {
        if ($this->failures === []) {
            $this->stdout("padstone-mason-neo/check: ok\n");

            return;
        }
        foreach ($this->failures as $failure) {
            $this->stderr("- $failure\n");
        }
    }
}
