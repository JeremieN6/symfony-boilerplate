<?php


namespace App\Controller;


use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Repository\InvoiceRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UsersRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


class WebhookController extends AbstractController
{
    #[Route('/webhook/stripe', name: 'app_webhook_stripe')]
    public function index(
        LoggerInterface $logger,
        ManagerRegistry $doctrine,
        UsersRepository $usersRepository,
        PlanRepository $planRepository,
        SubscriptionRepository $subscriptionRepository,
        InvoiceRepository $invoiceRepository,
        EntityManagerInterface $em
    ): Response {
        \Stripe\Stripe::setApiKey($this->getParameter('stripe_sk'));
        $event = null;


        // Check request
        $endpoint_secret = $this->getParameter('stripe_webhook_secret');
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            $logger->info('Webhook Stripe Invalid payload');
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            $logger->info('Webhook Stripe Invalid signature');
            http_response_code(403);
            exit();
        }


        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                $logger->info('Webhook Stripe connect checkout.session.completed');
                $session = $event->data->object;
                $subscriptionId = $session->subscription;


                $stripe = new \Stripe\StripeClient($this->getParameter('stripe_sk'));
                $subscriptionStripe = $stripe->subscriptions->retrieve($subscriptionId, array());
                $planId = $subscriptionStripe->plan->id;


                // Get user
                $customerEmail = $session->customer_details->email;
                $user = $usersRepository->findOneBy(['email' => $customerEmail]);
                if (!$user) {
                    $logger->info('Webhook Stripe user not found');
                    http_response_code(404);
                    exit();
                }


                // Disable old subscription
                // dump($user->getId());
                $activeSub = $subscriptionRepository->findActiveSub($user->getId());
                if ($activeSub) {
                    \Stripe\Subscription::update(
                        $activeSub->getStripeId(),
                        [
                            'cancel_at_period_end' => false,
                        ]
                    );

                    $activeSub->setIsActive(false);
                    $em->persist($activeSub);
                }


                // Get plan
                $plan = $planRepository->findOneBy(['stripeId' => $planId]);
                if (!$plan) {
                    $logger->info('Webhook Stripe plan not found');
                    http_response_code(404);
                    exit();
                }


                $subscription = new Subscription();
                $subscription->setPlan($plan);
                $subscription->setStripeId($subscriptionStripe->id);
                $subscription->setCurrentPeriodStart(new \Datetime(date('c', $subscriptionStripe->current_period_start)));
                $subscription->setCurrentPeriodEnd(new \Datetime(date('c', $subscriptionStripe->current_period_end)));
                $subscription->setUser($user);
                $subscription->setIsActive(true);
                $user->setStripeId($session->customer);
                // dd($subscription);
                $em->persist($subscription);
                $em->flush();
                break;
            case 'invoice.paid':
                $subscriptionId = $event->data->object->subscription;
                if (!$subscriptionId) {
                    $logger->info('No subscription');
                    break;
                }


                $subscription = null;
                for ($i = 0; $i <= 4 && $subscription === null; $i++) {
                    $subscription = $subscriptionRepository->findOneBy(['stripeId' => $subscriptionId]);
                    if ($subscription) {
                        break;
                    }
                    sleep(5);
                }


                if ($subscription) {
                    // Vous avez trouvé la subscription, vous pouvez maintenant obtenir son ID
                    $subscriptionId = $subscription->getId();
                } else {
                    $logger->info('Subscription not found in the database');
                    break;
                }


                $invoice = new Invoice();
                $invoice->setStripeId($event->data->object->id);
                $invoice->setSubscription($subscription);
                $invoice->setNumber($event->data->object->number);
                $invoice->setAmountPaid($event->data->object->amount_paid);
                // Hosted invoice url is now generated by formator
                $invoice->setHostedInvoiceUrl($event->data->object->hosted_invoice_url);

                $em->persist($invoice);
                $em->flush();


                break;
            default:
                // Unexpected event type
                http_response_code(400);
                exit();
        }


        http_response_code(200);


        $response = new Response('success');
        $response->headers->set('Content-Type', 'application/json');


        return $response;
    }
}
