<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller\Crudit;

use Lle\CruditBundle\Controller\AbstractCrudController;
use Lle\CruditBundle\Controller\TraitCrudController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Workflow\Registry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crudit controller limited to the list, the show page and the export (routes _index, _show, _export).
 *
 * The other actions of TraitCrudController are redeclared here without #[Route]: their routes do not exist, whatever
 * the roles granted. "autocomplete" goes too: Crudit does not check any role on it.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
abstract class AbstractReadOnlyCrudController extends AbstractCrudController
{
    use TraitCrudController;

    public function new(Request $request): Response
    {
        throw $this->readOnly();
    }

    public function edit(Request $request, mixed $id): Response
    {
        throw $this->readOnly();
    }

    public function delete(Request $request): Response
    {
        throw $this->readOnly();
    }

    public function sort(Request $request): JsonResponse
    {
        throw $this->readOnly();
    }

    public function autocomplete(Request $request): Response
    {
        throw $this->readOnly();
    }

    public function editdata(string $id, Request $request, TranslatorInterface $translator, ValidatorInterface $validator): Response
    {
        throw $this->readOnly();
    }

    public function workflowTransition(Request $request, Registry $wfRegistry, mixed $id): Response
    {
        throw $this->readOnly();
    }

    protected function readOnly(): NotFoundHttpException
    {
        return $this->createNotFoundException();
    }
}
