<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentSkills\Catalogue\PublishedSkills;
use Koh\SyliusAgentSkills\Controller\AgentSkillsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_agent_skills_index', '/.well-known/agent-skills/index.json')
        ->controller([AgentSkillsController::class, 'index'])
        ->methods(['GET', 'HEAD']);

    // The same name rule the index applies, so every entry it lists resolves
    // here; it also keeps the route from swallowing index.json.
    $routes->add('koh_agent_skills_document', '/.well-known/agent-skills/{name}/SKILL.md')
        ->controller([AgentSkillsController::class, 'document'])
        ->requirements(['name' => PublishedSkills::NAME_PATTERN])
        ->methods(['GET', 'HEAD']);
};
