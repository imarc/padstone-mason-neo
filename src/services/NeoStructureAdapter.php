<?php

namespace imarc\padstonemasonneo\services;

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use benf\neo\models\BlockType;
use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidFieldException;
use imarc\padstonemason\services\FieldSupport;
use imarc\padstonemason\services\structure\StructureAdapterInterface;
use imarc\padstonemason\services\structure\StructureHelpers;

/**
 * Top-level Neo structure ops for Mason (v1). Nested level create/move is refused.
 */
class NeoStructureAdapter implements StructureAdapterInterface
{
    public function __construct(private StructureHelpers $helpers)
    {
    }

    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof NeoField;
    }

    public function ownsBlock(ElementInterface $block): bool
    {
        return $block instanceof Block;
    }

    public function describeTypes(FieldInterface $field): array
    {
        if (!$field instanceof NeoField) {
            return [];
        }

        $types = [];
        foreach ($field->getBlockTypes() as $blockType) {
            if (!$blockType->enabled) {
                continue;
            }
            $types[] = [
                'handle' => $blockType->handle,
                'name' => $blockType->name,
                'topLevel' => (bool)$blockType->topLevel,
                'fields' => $this->helpers->describeFieldsFromLayout($blockType->getFieldLayout()),
            ];
        }

        return $types;
    }

    public function readField(Entry $owner, FieldInterface $field): array
    {
        if (!$field instanceof NeoField) {
            return $this->helpers->countMeta(null, null, 0) + ['blocks' => []];
        }

        $blocks = $this->topLevelBlocks($owner, $field);
        $items = [];
        foreach ($blocks as $block) {
            $items[] = $this->readBlock($block);
        }

        return $this->topLevelMeta($field, count($blocks)) + ['blocks' => $items];
    }

    public function summarizeField(Entry $owner, FieldInterface $field): array
    {
        if (!$field instanceof NeoField) {
            return $this->helpers->countMeta(null, null, 0) + ['blocks' => []];
        }

        $blocks = $this->topLevelBlocks($owner, $field);
        $items = [];
        foreach ($blocks as $block) {
            $items[] = [
                'id' => (int)$block->id,
                'type' => $block->getType()->handle,
                'signal' => $this->helpers->blockSignal($block, $block->getType()->getFieldLayout()),
            ];
        }

        return $this->topLevelMeta($field, count($blocks)) + ['blocks' => $items];
    }

    public function addBlock(
        Entry $owner,
        FieldInterface $field,
        string $typeHandle,
        ?int $position,
        array $values,
        array $nested,
        ?User $user,
    ): array {
        if (!$field instanceof NeoField) {
            return $this->helpers->fail('That field is not a Neo field.');
        }

        if ($nested !== []) {
            return $this->helpers->fail('Nested Neo level edits are not supported. Add top-level blocks only.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $type = $this->typeOnField($field, $typeHandle);
        if (!$type) {
            return $this->helpers->fail('That block type is not available.');
        }
        if (!$type->topLevel) {
            return $this->helpers->fail('Nested Neo level edits are not supported. That block type is not allowed at the top level.');
        }

        $prepared = $this->helpers->prepareFieldValuesFromLayout($type->getFieldLayout(), $values, $user);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $top = $this->topLevelBlocks($owner, $field);
        $count = count($top);
        $max = $field->maxTopBlocks ?: $field->maxBlocks;
        if ($max && $count >= (int)$max) {
            return $this->helpers->fail($field->handle . ' allows at most ' . $max . ' top-level blocks.');
        }

        return $this->helpers->mutate(function() use ($owner, $field, $typeHandle, $position, $prepared, $top) {
            [$blocks, $sort] = $this->payload($owner, $field);
            $key = 'new1';
            $blocks[$key] = [
                'type' => $typeHandle,
                'enabled' => true,
                'level' => 1,
                'fields' => $prepared['fields'],
            ];

            $topIds = array_map(fn(Block $b) => (string)$b->id, $top);
            if ($position === null || $position >= count($topIds)) {
                $topIds[] = $key;
            } else {
                array_splice($topIds, max(0, $position), 0, [$key]);
            }

            // Keep non-top-level blocks in relative place after their parents.
            $newSort = $this->rebuildSortWithTopOrder($sort, $blocks, $topIds);
            $before = array_map(fn(Block $b) => (int)$b->id, $top);
            $saved = $this->saveOwner($owner, $field, $blocks, $newSort);
            if (!$saved['ok']) {
                return $saved;
            }

            $owner = $saved['entry'];
            $created = null;
            foreach ($this->topLevelBlocks($owner, $field) as $block) {
                if (!in_array((int)$block->id, $before, true)) {
                    $created = $block;
                    break;
                }
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blockId' => $created?->id,
                'type' => $typeHandle,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readField($owner, $field)['blocks'],
            ], $this->helpers->previewPayload($this->helpers->rootEntry($owner)));
        });
    }

    public function updateBlock(ElementInterface $block, array $values, ?User $user): array
    {
        if (!$block instanceof Block) {
            return $this->helpers->fail('That element is not a Neo block.');
        }

        if ((int)$block->level > 1) {
            return $this->helpers->fail('Nested Neo level edits are not supported in this version.');
        }

        $owner = $block->getOwner();
        if (!$owner instanceof Entry) {
            return $this->helpers->fail('That block has no owner.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $block->getField();
        $prepared = $this->helpers->prepareFieldValuesFromLayout($block->getType()->getFieldLayout(), $values, $user);
        if (!$prepared['ok']) {
            return $prepared;
        }

        if ($prepared['fields'] === [] && $prepared['skipped'] !== []) {
            return [
                'ok' => true,
                'saved' => false,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readField($owner, $field)['blocks'],
            ];
        }

        if ($prepared['fields'] === []) {
            return $this->helpers->fail('Nothing to update.');
        }

        $canonical = $this->helpers->rootEntry($owner)->getCanonical(true);
        $beforeCanonical = $this->helpers->snapshot($canonical);

        return $this->helpers->mutate(function() use ($owner, $field, $block, $prepared, $canonical, $beforeCanonical) {
            [$blocks, $sort] = $this->payload($owner, $field);
            $id = (string)$block->id;
            if (!isset($blocks[$id])) {
                return $this->helpers->fail('That block is not in the field.');
            }
            $blocks[$id]['fields'] = $prepared['fields'];
            $saved = $this->saveOwner($owner, $field, $blocks, $sort);
            if (!$saved['ok']) {
                return $saved;
            }

            $after = $this->helpers->snapshot($this->helpers->reload($canonical));
            if ($after !== $beforeCanonical && !$canonical->getIsUnpublishedDraft()) {
                return $this->helpers->fail('The live entry changed unexpectedly.');
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blockId' => $block->id,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readField($saved['entry'], $field)['blocks'],
            ], $this->helpers->previewPayload($this->helpers->rootEntry($saved['entry'])));
        });
    }

    public function removeBlock(ElementInterface $block, ?User $user): array
    {
        if (!$block instanceof Block) {
            return $this->helpers->fail('That element is not a Neo block.');
        }

        if ((int)$block->level > 1) {
            return $this->helpers->fail('Nested Neo level edits are not supported in this version.');
        }

        $owner = $block->getOwner();
        if (!$owner instanceof Entry) {
            return $this->helpers->fail('That block has no owner.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $block->getField();
        $top = $this->topLevelBlocks($owner, $field);
        $remainingTop = array_values(array_filter(
            $top,
            fn(Block $b) => (int)$b->id !== (int)$block->id,
        ));
        $min = $field->minTopBlocks ?: $field->minBlocks;
        if ($min && count($remainingTop) < (int)$min) {
            return $this->helpers->fail($field->handle . ' requires at least ' . $min . ' top-level blocks.');
        }

        return $this->helpers->mutate(function() use ($owner, $field, $block) {
            [$blocks, $sort] = $this->payload($owner, $field);
            $removeId = (string)$block->id;
            // Drop the top-level block and any descendants that follow at deeper levels until next top-level.
            $drop = false;
            $newSort = [];
            foreach ($sort as $id) {
                if ($id === $removeId) {
                    $drop = true;
                    unset($blocks[$id]);
                    continue;
                }
                if ($drop) {
                    $level = (int)($blocks[$id]['level'] ?? 1);
                    if ($level <= 1) {
                        $drop = false;
                    } else {
                        unset($blocks[$id]);
                        continue;
                    }
                }
                $newSort[] = $id;
            }

            $saved = $this->saveOwner($owner, $field, $blocks, $newSort);
            if (!$saved['ok']) {
                return $saved;
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blocks' => $this->readField($saved['entry'], $field)['blocks'],
            ], $this->helpers->previewPayload($this->helpers->rootEntry($saved['entry'])));
        });
    }

    public function reorderBlocks(Entry $owner, FieldInterface $field, array $orderedIds, ?User $user): array
    {
        if (!$field instanceof NeoField) {
            return $this->helpers->fail('That field is not a Neo field.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $top = $this->topLevelBlocks($owner, $field);
        $current = array_map(fn(Block $b) => (string)$b->id, $top);
        $ordered = array_map('strval', $orderedIds);
        $currentSorted = $current;
        $orderedSorted = $ordered;
        sort($currentSorted);
        sort($orderedSorted);
        if ($currentSorted !== $orderedSorted || count($ordered) !== count(array_unique($ordered))) {
            return $this->helpers->fail('That order is invalid.');
        }

        return $this->helpers->mutate(function() use ($owner, $field, $ordered) {
            [$blocks, $sort] = $this->payload($owner, $field);
            $newSort = $this->rebuildSortWithTopOrder($sort, $blocks, $ordered);
            $saved = $this->saveOwner($owner, $field, $blocks, $newSort);
            if (!$saved['ok']) {
                return $saved;
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blocks' => $this->readField($saved['entry'], $field)['blocks'],
            ], $this->helpers->previewPayload($this->helpers->rootEntry($saved['entry'])));
        });
    }

    public function findBlockOnOwner(Entry $root, int $blockId): ?ElementInterface
    {
        if ($blockId < 1) {
            return null;
        }

        foreach ($this->helpers->customFields($root->getType()) as $custom) {
            $field = $custom['field'];
            if (!$field instanceof NeoField) {
                continue;
            }
            foreach ($this->allBlocks($root, $field) as $block) {
                if ((int)$block->id === $blockId) {
                    return $block;
                }
            }
        }

        return null;
    }

    public function resolveBlock(int $id, ?int $siteId): ?ElementInterface
    {
        $block = Block::find()->id($id)->status(null)->drafts(null)->siteId($siteId)->one();

        return $block instanceof Block ? $block : null;
    }

    /**
     * @return array{minEntries: ?int, maxEntries: ?int, count: int, atMin: bool, atMax: bool, hint?: string}
     */
    private function topLevelMeta(NeoField $field, int $count): array
    {
        $min = $field->minTopBlocks ?: $field->minBlocks;
        $max = $field->maxTopBlocks ?: $field->maxBlocks;

        return $this->helpers->countMeta($min, $max, $count);
    }

    /**
     * @return Block[]
     */
    private function allBlocks(Entry $owner, NeoField $field): array
    {
        $query = $owner->getFieldValue($field->handle);
        $blocks = $query->status(null)->all();

        return array_values(array_filter($blocks, fn($b) => $b instanceof Block));
    }

    /**
     * @return Block[]
     */
    private function topLevelBlocks(Entry $owner, NeoField $field): array
    {
        return array_values(array_filter(
            $this->allBlocks($owner, $field),
            fn(Block $b) => (int)$b->level <= 1,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function readBlock(Block $block): array
    {
        $item = [
            'id' => $block->id,
            'type' => $block->getType()->handle,
            'level' => (int)$block->level,
            'fields' => [],
        ];
        foreach ($this->helpers->customFieldsFromLayout($block->getType()->getFieldLayout()) as $custom) {
            $nested = $custom['field'];
            if (FieldSupport::isStructureContainer($nested)) {
                continue;
            }
            if (FieldSupport::isReadOnly($nested)) {
                continue;
            }
            try {
                $raw = $block->getFieldValue($nested->handle);
                if (!FieldSupport::canRepresent($nested, $raw)) {
                    continue;
                }
                $item['fields'][$nested->handle] = FieldSupport::readValue($nested, $raw);
            } catch (InvalidFieldException) {
            }
        }

        return $item;
    }

    private function typeOnField(NeoField $field, string $handle): ?BlockType
    {
        foreach ($field->getBlockTypes() as $blockType) {
            if ($blockType->handle === $handle) {
                return $blockType;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: string[]}
     */
    private function payload(Entry $owner, NeoField $field): array
    {
        $blocks = [];
        $sort = [];
        foreach ($this->allBlocks($owner, $field) as $block) {
            $key = (string)$block->id;
            $sort[] = $key;
            $blocks[$key] = [
                'type' => $block->getType()->handle,
                'enabled' => $block->enabled,
                'level' => (int)$block->level,
            ];
        }

        return [$blocks, $sort];
    }

    /**
     * @param string[] $sort
     * @param array<string, array<string, mixed>> $blocks
     * @param string[] $topOrder
     * @return string[]
     */
    private function rebuildSortWithTopOrder(array $sort, array $blocks, array $topOrder): array
    {
        $children = [];
        $currentTop = null;
        foreach ($sort as $id) {
            $level = (int)($blocks[$id]['level'] ?? 1);
            if ($level <= 1) {
                $currentTop = $id;
                $children[$id] = $children[$id] ?? [];
            } elseif ($currentTop !== null) {
                $children[$currentTop][] = $id;
            }
        }

        $newSort = [];
        foreach ($topOrder as $topId) {
            $newSort[] = $topId;
            foreach ($children[$topId] ?? [] as $childId) {
                $newSort[] = $childId;
            }
        }

        return $newSort;
    }

    /**
     * @param array<string, array<string, mixed>> $blocks
     * @param string[] $sort
     * @return array{ok: bool, entry?: Entry, error?: string, errors?: array}
     */
    private function saveOwner(Entry $owner, NeoField $field, array $blocks, array $sort): array
    {
        $owner->setFieldValue($field->handle, [
            'blocks' => $blocks,
            'sortOrder' => $sort,
        ]);
        $owner->setScenario(Element::SCENARIO_DEFAULT);
        if (!Craft::$app->getElements()->saveElement($owner)) {
            return $this->helpers->fail($this->helpers->errorText($owner), $owner->getErrors());
        }

        return ['ok' => true, 'entry' => $this->helpers->reload($owner)];
    }
}
