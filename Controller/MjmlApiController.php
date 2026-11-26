<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMjmlApiBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Mautic\ApiBundle\Controller\CommonApiController;
use Mautic\ApiBundle\Helper\EntityResultHelper;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\AppVersion;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder;
use MauticPlugin\MauticMjmlApiBundle\Helper\MjmlCompiler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Creates and updates segment emails from MJML.
 *
 * Compiled HTML is stored on the email. The original MJML is stored on the
 * GrapesJS builder row so the email stays editable in the visual builder.
 *
 * @extends CommonApiController<Email>
 */
class MjmlApiController extends CommonApiController
{
    /**
     * Dates without an offset are interpreted in this timezone.
     * ISO-8601 values that already include an offset keep that offset.
     */
    private const TIMEZONE = 'Europe/Ljubljana';

    /**
     * @param ModelFactory<Email> $modelFactory
     */
    public function __construct(
        CorePermissions $security,
        Translator $translator,
        EntityResultHelper $entityResultHelper,
        RouterInterface $router,
        FormFactoryInterface $formFactory,
        AppVersion $appVersion,
        RequestStack $requestStack,
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        EventDispatcherInterface $dispatcher,
        CoreParametersHelper $coreParametersHelper,
        private readonly EmailModel $emailModel,
        private readonly ListModel $listModel,
        private readonly MjmlCompiler $mjmlCompiler,
    ) {
        $this->model            = $emailModel;
        $this->entityClass      = Email::class;
        $this->entityNameOne    = 'email';
        $this->entityNameMulti  = 'emails';
        $this->serializerGroups = [
            'emailDetails',
            'categoryList',
            'publishDetails',
            'assetList',
            'formList',
            'leadListList',
        ];

        parent::__construct(
            $security,
            $translator,
            $entityResultHelper,
            $router,
            $formFactory,
            $appVersion,
            $requestStack,
            $doctrine,
            $modelFactory,
            $dispatcher,
            $coreParametersHelper,
        );
    }

    /**
     * POST /api/emails/mjml/new
     */
    public function createEmailAction(Request $request): Response
    {
        $data = $this->getPayload($request);
        if ($data instanceof Response) {
            return $data;
        }

        if (empty($data['name'])) {
            return $this->returnError('name is required', Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['subject'])) {
            return $this->returnError('subject is required', Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['mjml']) || !is_string($data['mjml'])) {
            return $this->returnError('mjml is required', Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['lists']) || !is_array($data['lists'])) {
            return $this->returnError('lists array is required', Response::HTTP_BAD_REQUEST);
        }

        $email = new Email();

        $denied = $this->denyUnless($this->checkEntityAccess($email, 'create'));
        if ($denied instanceof Response) {
            return $denied;
        }

        $isPublished = $this->toBool($data['isPublished'] ?? false);
        if ($isPublished) {
            $denied = $this->denyUnless($this->checkEntityAccess($email, 'publish'));
            if ($denied instanceof Response) {
                return $denied;
            }
        }

        try {
            $compiledHtml = $this->mjmlCompiler->compile($data['mjml']);
            if (!is_string($compiledHtml) || '' === $compiledHtml) {
                return $this->returnError('Failed to compile MJML', Response::HTTP_BAD_REQUEST);
            }

            $email->setName((string) $data['name']);
            $email->setSubject((string) $data['subject']);
            $email->setCustomHtml($compiledHtml);
            $email->setEmailType(is_string($data['emailType'] ?? null) && '' !== $data['emailType'] ? $data['emailType'] : 'list');
            $email->setIsPublished($isPublished);
            $email->setLanguage(is_string($data['language'] ?? null) && '' !== $data['language'] ? $data['language'] : 'en');
            $email->setTemplate(is_string($data['template'] ?? null) && '' !== $data['template'] ? $data['template'] : 'blank');

            $this->applySender($email, $data, false);
            $this->applyPublishWindow($email, $data, false);
            $this->applyUtmTags($email, $data);
            $this->applyLists($email, $data['lists']);

            $this->emailModel->saveEntity($email);
            $this->saveMjmlSource($email, $data['mjml']);

            return $this->emailResponse($this->emailModel->getEntity($email->getId()), Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PATCH /api/emails/mjml/{id}/edit
     */
    public function editEmailAction(Request $request, string $id): Response
    {
        $data = $this->getPayload($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $email = $this->emailModel->getEntity((int) $id);
            if (!$email instanceof Email) {
                return $this->notFound();
            }

            $denied = $this->denyUnless($this->checkEntityAccess($email, 'edit'));
            if ($denied instanceof Response) {
                return $denied;
            }

            if (array_key_exists('isPublished', $data) && $this->toBool($data['isPublished'])) {
                $denied = $this->denyUnless($this->checkEntityAccess($email, 'publish'));
                if ($denied instanceof Response) {
                    return $denied;
                }
            }

            if (isset($data['mjml'])) {
                if (!is_string($data['mjml']) || '' === $data['mjml']) {
                    return $this->returnError('mjml must be a non-empty string', Response::HTTP_BAD_REQUEST);
                }

                $compiledHtml = $this->mjmlCompiler->compile($data['mjml']);
                if (!is_string($compiledHtml) || '' === $compiledHtml) {
                    return $this->returnError('Failed to compile MJML', Response::HTTP_BAD_REQUEST);
                }

                $email->setCustomHtml($compiledHtml);
            }

            if (isset($data['name'])) {
                $email->setName((string) $data['name']);
            }
            if (isset($data['subject'])) {
                $email->setSubject((string) $data['subject']);
            }
            if (array_key_exists('isPublished', $data)) {
                $email->setIsPublished($this->toBool($data['isPublished']));
            }

            $this->applySender($email, $data, true);
            $this->applyPublishWindow($email, $data, true);
            $this->applyUtmTags($email, $data);

            if (isset($data['lists'])) {
                if (!is_array($data['lists'])) {
                    return $this->returnError('lists must be an array', Response::HTTP_BAD_REQUEST);
                }

                foreach ($email->getLists()->toArray() as $list) {
                    $email->removeList($list);
                }

                $this->applyLists($email, $data['lists']);
            }

            $this->emailModel->saveEntity($email);

            if (isset($data['mjml']) && is_string($data['mjml'])) {
                $this->saveMjmlSource($email, $data['mjml']);
            }

            return $this->emailResponse($this->emailModel->getEntity($email->getId()), Response::HTTP_OK);
        } catch (\Throwable $e) {
            return $this->returnError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applySender(Email $email, array $data, bool $allowEmpty): void
    {
        foreach (['fromAddress' => 'setFromAddress', 'fromName' => 'setFromName', 'replyToAddress' => 'setReplyToAddress'] as $field => $setter) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if (!is_string($value)) {
                continue;
            }

            if (!$allowEmpty && '' === $value) {
                continue;
            }

            $email->{$setter}($value);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyPublishWindow(Email $email, array $data, bool $allowClear): void
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        foreach (['publishUp' => 'setPublishUp', 'publishDown' => 'setPublishDown'] as $field => $setter) {
            if ($allowClear) {
                if (!array_key_exists($field, $data)) {
                    continue;
                }
            } elseif (empty($data[$field])) {
                continue;
            }

            $value = $data[$field] ?? null;
            if (!is_string($value) || '' === $value) {
                if ($allowClear) {
                    $email->{$setter}(null);
                }

                continue;
            }

            try {
                $email->{$setter}(new \DateTime($value, $timezone));
            } catch (\Exception) {
                // Ignore invalid date format, matching the previous API behaviour.
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyUtmTags(Email $email, array $data): void
    {
        if (isset($data['utmTags']) && is_array($data['utmTags'])) {
            $email->setUtmTags($data['utmTags']);
        }
    }

    /**
     * @param array<mixed> $listIds
     */
    private function applyLists(Email $email, array $listIds): void
    {
        foreach ($listIds as $listId) {
            $normalizedId = $this->normalizeId($listId);
            if (null === $normalizedId) {
                continue;
            }

            $list = $this->listModel->getEntity($normalizedId);
            if ($list instanceof LeadList) {
                $email->addList($list);
            }
        }
    }

    private function saveMjmlSource(Email $email, string $mjml): void
    {
        if (!class_exists(GrapesJsBuilder::class)) {
            throw new \RuntimeException('The GrapesJS Builder plugin must be installed to store MJML source.');
        }

        $em = $this->entityManager();
        $grapesJsBuilder = $em->getRepository(GrapesJsBuilder::class)->findOneBy(['email' => $email]);

        if (!$grapesJsBuilder instanceof GrapesJsBuilder) {
            $grapesJsBuilder = new GrapesJsBuilder();
            $grapesJsBuilder->setEmail($email);
        }

        $grapesJsBuilder->setCustomMjml($mjml);
        $em->persist($grapesJsBuilder);
        $em->flush();
    }

    private function emailResponse(?Email $email, int $status): Response
    {
        if (!$email instanceof Email) {
            return $this->returnError('Email could not be loaded after save.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $view = $this->view(['email' => $email], $status);
        $this->setSerializationContext($view);

        return $this->handleView($view);
    }

    /**
     * FOSRest normally decodes JSON into the request bag. Fall back to the raw
     * body when that listener did not run.
     *
     * @return array<string, mixed>|Response
     */
    private function getPayload(Request $request): array|Response
    {
        $data = $request->request->all();
        if ([] !== $data) {
            return $data;
        }

        $content = $request->getContent();
        if (!is_string($content) || '' === trim($content)) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->returnError('Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($decoded)) {
            return $this->returnError('Request body must be a JSON object.', Response::HTTP_BAD_REQUEST);
        }

        return $decoded;
    }

    private function denyUnless(mixed $access): ?Response
    {
        if ($access instanceof Response) {
            return $access;
        }

        if (true !== $access) {
            return $this->accessDenied();
        }

        return null;
    }

    private function normalizeId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && 1 === preg_match('/^[1-9]\d*$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private function entityManager(): ObjectManager
    {
        return $this->doctrine->getManager();
    }
}
