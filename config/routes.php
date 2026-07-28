<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentSkills\Controller\AgentSkillsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_agent_skills_index', '/.well-known/agent-skills/index.json')
        ->controller([AgentSkillsController::class, 'index'])
        ->methods(['GET', 'HEAD']);

    // Skill names are lowercase alphanumeric plus hyphens per the RFC; the
    // requirement keeps the route from swallowing index.json.
    $routes->add('koh_agent_skills_document', '/.well-known/agent-skills/{name}/SKILL.md')
        ->controller([AgentSkillsController::class, 'document'])
        ->requirements(['name' => '[a-z0-9][a-z0-9-]*'])
        ->methods(['GET', 'HEAD']);
};
