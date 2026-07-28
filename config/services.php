<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusAgentSkills\Agent\AgentSkillsEndpointProvider;
use Koh\SyliusAgentSkills\Controller\AgentSkillsController;
use Koh\SyliusAgentSkills\Renderer\SkillDocumentRenderer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SkillDocumentRenderer::class)->public();

    $services->set(AgentSkillsController::class)
        ->args([
            service(AgentSkillRegistry::class),
            service(SkillDocumentRenderer::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(AgentSkillsEndpointProvider::class)
        ->args([service(AgentSkillRegistry::class)])
        ->tag('koh_agent.endpoint');
};
