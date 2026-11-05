<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Catalogue;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Psr\Log\LoggerInterface;

/**
 * The skills this shop may actually publish in the discovery index.
 *
 * The registry is shared with the A2A card and the MCP server card, which are
 * more forgiving than the Agent Skills discovery RFC (v0.2.0). A skill the RFC
 * does not admit is left out of the index rather than published broken: an
 * entry whose name the document route rejects, or whose digest cannot match
 * the artifact at its URL, is one a verifying client throws away anyway — and
 * a strict client may throw away the whole index with it.
 *
 * The index, the SKILL.md route and the endpoint announcement all go through
 * this one class, so they never disagree about which skills exist.
 */
final class PublishedSkills
{
    /**
     * Agent Skills name rules: 1–64 characters, lowercase alphanumeric and
     * hyphens, no leading, trailing or consecutive hyphen.
     */
    public const NAME_PATTERN = '[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){0,63}';

    public const DESCRIPTION_MAX_LENGTH = 1024;

    public const TYPE_SKILL_MD = 'skill-md';

    public const TYPE_ARCHIVE = 'archive';

    private const DIGEST_PATTERN = '/^sha256:[0-9a-f]{64}$/';

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^'.self::NAME_PATTERN.'$/D', $name);
    }

    /**
     * Whether this package renders the artifact itself (and so computes its
     * digest), as opposed to pointing at one the provider hosts elsewhere.
     */
    public static function isServedLocally(AgentSkill $skill): bool
    {
        return self::TYPE_SKILL_MD === $skill->type && null === $skill->url;
    }

    /** @return list<AgentSkill> */
    public function all(AgentContext $context): array
    {
        $published = [];

        foreach ($this->skills->all($context) as $skill) {
            $problem = $this->problem($skill);

            if (null !== $problem) {
                $this->logger?->warning('Agent skill "{id}" is left out of the agent-skills index: {problem}.', [
                    'id' => $skill->id,
                    'problem' => $problem,
                ]);

                continue;
            }

            $published[] = $skill;
        }

        return $published;
    }

    /** The skill whose SKILL.md this package serves under that name, if any. */
    public function find(string $name, AgentContext $context): ?AgentSkill
    {
        foreach ($this->all($context) as $skill) {
            if ($skill->id === $name && self::isServedLocally($skill)) {
                return $skill;
            }
        }

        return null;
    }

    private function problem(AgentSkill $skill): ?string
    {
        if (!self::isValidName($skill->id)) {
            return 'the name must be 1-64 lowercase letters, digits and single inner hyphens';
        }

        if ('' === trim($skill->description)) {
            return 'the description is empty';
        }

        if (mb_strlen($skill->description) > self::DESCRIPTION_MAX_LENGTH) {
            return \sprintf('the description exceeds %d characters', self::DESCRIPTION_MAX_LENGTH);
        }

        if (!\in_array($skill->type, [self::TYPE_SKILL_MD, self::TYPE_ARCHIVE], true)) {
            return \sprintf('the type "%s" is neither "skill-md" nor "archive"', $skill->type);
        }

        if (null !== $skill->digest && 1 !== preg_match(self::DIGEST_PATTERN, $skill->digest)) {
            return 'the digest is not "sha256:" followed by 64 lowercase hex digits';
        }

        if (self::isServedLocally($skill)) {
            return null;
        }

        // Archives are never rendered here, and a SKILL.md hosted elsewhere is
        // not the document this package would hash: either way only the
        // provider knows the artifact's bytes.
        if (null === $skill->url) {
            return 'an archive needs the URL of the artifact';
        }

        if (null === $skill->digest) {
            return 'an artifact hosted elsewhere needs its own digest';
        }

        return null;
    }
}
