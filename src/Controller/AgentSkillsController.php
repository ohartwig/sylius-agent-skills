<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusAgentSkills\Renderer\SkillDocumentRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the Agent Skills discovery index and the SKILL.md documents it names.
 */
final class AgentSkillsController
{
    public const SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly SkillDocumentRenderer $renderer,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $skills = $this->skills->all($context);

        if ([] === $skills) {
            throw new NotFoundHttpException('No agent skills are registered.');
        }

        $entries = [];
        foreach ($skills as $skill) {
            // Digest over the document this shop will actually serve — computed
            // now, not configured. A digest kept in configuration goes stale the
            // moment a description changes, and a client that verifies it then
            // rejects a perfectly good skill.
            $document = $this->renderer->render($skill, $context);

            $entries[] = [
                'name' => $skill->id,
                'type' => $skill->type,
                'description' => $skill->description,
                'url' => $skill->url ?? $context->url('/.well-known/agent-skills/'.$skill->id.'/SKILL.md'),
                'digest' => $skill->digest ?? $this->renderer->digest($document),
            ];
        }

        return $this->responder->respond([
            '$schema' => self::SCHEMA,
            'skills' => $entries,
        ], request: $request);
    }

    public function document(string $name, Request $request): Response
    {
        $context = $this->contextFactory->create();

        foreach ($this->skills->all($context) as $skill) {
            if ($skill->id === $name) {
                return $this->responder->respondWithBody(
                    $this->renderer->render($skill, $context),
                    'text/markdown',
                    request: $request,
                );
            }
        }

        throw new NotFoundHttpException(\sprintf('No skill named "%s".', $name));
    }

    /** @return list<AgentSkill> */
    public function skills(): array
    {
        return $this->skills->all($this->contextFactory->create());
    }
}
