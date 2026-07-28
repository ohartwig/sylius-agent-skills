<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Renderer;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentSkill;

/**
 * Renders one skill as the SKILL.md the index points at.
 *
 * The index carries a SHA-256 digest of each artifact, so whatever is served
 * here has to be byte-identical to whatever the digest was computed over. Both
 * go through this one method for exactly that reason — a document rendered in
 * one place and hashed in another drifts the first time either changes.
 */
final class SkillDocumentRenderer
{
    public function render(AgentSkill $skill, AgentContext $context): string
    {
        $out = '# '.$skill->name."\n\n".$skill->description."\n";

        if ([] !== $skill->tags) {
            $out .= "\n**Tags:** ".implode(', ', $skill->tags)."\n";
        }

        $out .= "\n## Interface\n\n";
        $out .= '- **Input:** '.implode(', ', $skill->inputModes)."\n";
        $out .= '- **Output:** '.implode(', ', $skill->outputModes)."\n";

        $out .= "\n## Access\n\n";
        $out .= 'See '.$context->url('/auth.md')." for how to authenticate.\n";

        return $out;
    }

    public function digest(string $document): string
    {
        return 'sha256:'.hash('sha256', $document);
    }
}
