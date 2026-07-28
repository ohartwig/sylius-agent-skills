<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;

/**
 * Announces the index — but only while there is a skill in it, since the route
 * 404s on an empty registry.
 */
final class AgentSkillsEndpointProvider implements AgentEndpointProviderInterface
{
    public function __construct(private readonly AgentSkillRegistry $skills)
    {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        if ([] === $this->skills->all($context)) {
            return;
        }

        yield new AgentEndpoint(
            path: '/.well-known/agent-skills/index.json',
            title: 'Agent skills',
            protocol: 'agent-skills',
            description: 'What an agent can ask this shop to do.',
        );
    }
}
