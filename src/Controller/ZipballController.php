<?php

declare(strict_types=1);

namespace Packeton\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\Vars;
use Packeton\Composer\JsonResponse;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Entity\Zipball;
use Packeton\Model\UploadZipballStorage;
use Packeton\Service\DistManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ZipballController extends AbstractController
{
    public function __construct(
        protected DistManager $dm,
        protected UploadZipballStorage $storage,
        protected ManagerRegistry $registry,
    ) {
    }

    #[Route('/archive/upload', name: 'archive_upload', methods: ["POST"])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('archive_upload', $request->get('token'))) {
            return new JsonResponse(['error' => 'Csrf token is not a valid'], 400);
        }

        if (!$file = $request->files->get('archive')) {
            return new JsonResponse(['error' => 'File is empty'], 400);
        }

        $result = $this->storage->save($file);
        return new JsonResponse($result, $result['code'] ?? 201);
    }

    #[Route('/archive/remove/{id}', name: 'archive_remove', methods: ["DELETE"])]
    public function remove(#[Vars] Zipball $zip, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('archive_upload', $request->get('token'))) {
            return new JsonResponse(['error' => 'Csrf token is not a valid'], 400);
        }
        if ($zip->isUsed()) {
            return new JsonResponse(['error' => 'You can not remove used zipball'], 400);
        }

        $this->storage->remove($zip);

        return new JsonResponse([], 204);
    }

    #[Route('/archive/list', name: 'archive_list')]
    public function zipballList(): Response
    {
        $data = $this->registry->getRepository(Zipball::class)->ajaxSelect();

        return new JsonResponse($data);
    }

    #[Route(
        '/zipball/{package}/{hash}',
        name: 'download_dist_package',
        requirements: ['package' => '%package_name_regex%', 'hash' => '[a-f0-9]{40}\.[a-z]+?'],
        methods: ['GET']
    )]
    public function zipballAction(#[Vars('name')] Package $package, string $hash): Response
    {
        if (!$this->dm->isEnabled() || !\preg_match('{[a-f0-9]{40}}i', $hash, $match) || !($reference = $match[0])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not Found'], 404);
        }

        $version = $package->getVersions()->findFirst(fn($k, $v) => $v->getReference() === $reference);
        if ($version instanceof Version) {
            if ($this->isGranted('ROLE_FULL_CUSTOMER', $version) and $path = $this->dm->getDistPath($version)) {
                return new BinaryFileResponse($path);
            }

            return $this->createNotFound();
        }

        try {
            $path = $this->dm->getDistByOrphanedRef($reference, $package, $version);
            $version = $package->getVersions()->findFirst(fn($k, $v) => $v->getVersion() === $version);

            if ($this->isGranted('ROLE_FULL_CUSTOMER', $version) || $this->isGranted('VIEW_ALL_VERSION', $package)) {
                return new BinaryFileResponse($path);
            }
        } catch (\Exception $e) {
            $msg = $this->isGranted('ROLE_MAINTAINER') ? $e->getMessage() : null;
            return $this->createNotFound($msg);
        }

        return $this->createNotFound();
    }

    protected function createNotFound(?string $msg = null): Response
    {
        return new JsonResponse(['status' => 'error', 'message' => $msg ?: 'Not Found'], 404);
    }
}
