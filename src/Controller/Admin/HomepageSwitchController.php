<?php

/*
 * This file is part of Monsieur Biz' Homepage plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusHomepagePlugin\Controller\Admin;

use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageInterface;
use MonsieurBiz\SyliusHomepagePlugin\Entity\HomepageSwitch;
use MonsieurBiz\SyliusHomepagePlugin\Exception\HomepageSwitchRejected;
use MonsieurBiz\SyliusHomepagePlugin\Form\Type\HomepageSwitchType;
use MonsieurBiz\SyliusHomepagePlugin\Repository\HomepageRepository;
use MonsieurBiz\SyliusHomepagePlugin\Service\HomepageAdminPermissions;
use MonsieurBiz\SyliusHomepagePlugin\Service\HomepageSwitchScheduler;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Resource\ResourceActions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HomepageSwitchController extends AbstractController
{
    private const INDEX_ROUTE = 'monsieurbiz_homepage_admin_homepage_switch_index';

    public function __construct(
        private EntityManagerInterface $homepageManager,
        private HomepageRepository $homepageRepository,
        private ChannelRepositoryInterface $channelRepository,
        private HomepageSwitchScheduler $scheduler,
        private HomepageAdminPermissions $permissions,
        private TranslatorInterface $translator,
        private string $timezone
    ) {
    }

    public function indexAction(Request $request): Response
    {
        $this->permissions->requirePermission($request, ResourceActions::INDEX);

        $historyFlag = $request->query->getString('history');
        if (!in_array($historyFlag, ['', '0', '1'], true)) {
            throw new BadRequestHttpException('Invalid homepage history filter.');
        }
        $history = '1' === $historyFlag;
        $target = $history ? '' : $request->query->getString('target');
        $status = $history ? '' : $request->query->getString('status');
        $channelCode = $history ? $request->query->getString('channel') : '';
        if (('' !== $target && (!preg_match('/^[1-9]\d{0,9}$/D', $target) || (int) $target > 2_147_483_647))
            || !in_array($status, ['', HomepageSwitch::PENDING], true)
            || strlen($channelCode) > 255
        ) {
            throw new BadRequestHttpException('Invalid homepage switch filters.');
        }
        $targetId = '' !== $target ? (int) $target : null;
        $channel = '' !== $channelCode ? $this->channelRepository->findOneByCode($channelCode) : null;
        if ('' !== $channelCode && null === $channel) {
            throw new BadRequestHttpException('Unknown homepage history channel.');
        }

        $criteria = $history ? ['status' => HomepageSwitch::EXECUTED] : [];
        if (null !== $targetId) {
            $criteria['target'] = $targetId;
        }
        if ('' !== $status) {
            $criteria['status'] = $status;
        }
        if (null !== $channel) {
            $criteria['channel'] = $channel;
        }

        return $this->render('@MonsieurBizSyliusHomepagePlugin/admin/homepage_switch/index.html.twig', [
            'switches' => $this->homepageManager->getRepository(HomepageSwitch::class)->findBy(
                $criteria,
                [$history ? 'executedAt' : 'scheduledAt' => 'DESC', 'id' => 'DESC']
            ),
            'timezone' => $this->timezone,
            'channels' => $this->channelRepository->findBy([], ['name' => 'ASC']),
            'filters' => [
                'target' => $targetId,
                'status' => '' !== $status ? $status : null,
                'history' => $history,
                'channel' => $channel?->getCode(),
            ],
        ]);
    }

    public function createAction(Request $request, int $id): Response
    {
        $this->permissions->requirePermission($request, ResourceActions::UPDATE);

        return $this->form($request, $this->findHomepage($id), 'create');
    }

    public function activateNowAction(Request $request, int $id): Response
    {
        $this->permissions->requirePermission($request, ResourceActions::UPDATE);

        return $this->form($request, $this->findHomepage($id), 'activate_now');
    }

    public function updateAction(Request $request, int $id): Response
    {
        $this->permissions->requirePermission($request, ResourceActions::UPDATE);
        $switch = $this->findSwitch($id);
        if (!$switch->isPending()) {
            throw new ConflictHttpException($this->translator->trans('monsieurbiz_homepage.ui.switch.error.not_pending'));
        }

        return $this->form($request, $switch->getTarget(), 'update', $switch);
    }

    public function cancelAction(Request $request, int $id): Response
    {
        $this->permissions->requirePermission($request, ResourceActions::UPDATE);
        if (!$this->isCsrfTokenValid('homepage_switch_cancel_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->scheduler->cancel($this->findSwitch($id));
        } catch (HomepageSwitchRejected $exception) {
            throw new ConflictHttpException($this->translator->trans($exception->getMessage()), $exception);
        }

        $this->addFlash('success', 'monsieurbiz_homepage.switch.cancelled');

        return $this->redirectToRoute(self::INDEX_ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    private function form(Request $request, HomepageInterface $homepage, string $mode, ?HomepageSwitch $switch = null): Response
    {
        $timezone = new DateTimeZone($this->timezone);
        $form = $this->createForm(HomepageSwitchType::class, null === $switch ? [] : [
            'scheduledAt' => $switch->getScheduledAt()->setTimezone($timezone)->format(HomepageSwitchType::LOCAL_FORMAT),
        ], [
            'mode' => $mode,
            'csrf_token_id' => 'homepage_switch_' . $mode . '_' . ($switch?->getId() ?? $homepage->getId()),
        ]);
        $form->handleRequest($request);

        if (null === $switch && method_exists($homepage, 'isEnabled') && !$homepage->isEnabled()) {
            $form->addError(new FormError($this->translator->trans('monsieurbiz_homepage.ui.switch.error.disabled')));
        } elseif ($form->isSubmitted() && $form->isValid()) {
            try {
                $scheduledAt = $form->has('scheduledAt')
                    ? HomepageSwitchType::parseLocalTime((string) $form->get('scheduledAt')->getData(), $timezone)
                    : null;
                if (null === $switch) {
                    /** @var ChannelInterface $channel */
                    $channel = $form->get('channel')->getData();
                    $this->scheduler->schedule($homepage, $channel, $scheduledAt);
                } else {
                    $this->scheduler->reschedule($switch, $scheduledAt ?? throw new \LogicException('Missing switch date field.'));
                }
                $this->addFlash('success', null === $switch ? 'monsieurbiz_homepage.switch.queued' : 'monsieurbiz_homepage.switch.rescheduled');

                return $this->redirectToRoute(self::INDEX_ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (HomepageSwitchRejected $exception) {
                $form->addError(new FormError($this->translator->trans($exception->getMessage())));
            }
        }

        return $this->render('@MonsieurBizSyliusHomepagePlugin/admin/homepage_switch/form.html.twig', [
            'form' => $form->createView(),
            'homepage' => $homepage,
            'switch' => $switch,
            'mode' => $mode,
            'timezone' => $this->timezone,
        ], $request->isMethod('POST') ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    private function findHomepage(int $id): HomepageInterface
    {
        $homepage = $this->homepageRepository->find($id);
        if (!$homepage instanceof HomepageInterface) {
            throw $this->createNotFoundException();
        }

        return $homepage;
    }

    private function findSwitch(int $id): HomepageSwitch
    {
        return $this->homepageManager->find(HomepageSwitch::class, $id) ?? throw $this->createNotFoundException();
    }
}
