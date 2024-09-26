<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Form\ForgotPasswordFormType;
use App\Form\ResetPasswordFormType; // Make sure you include this if you use it
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;


class UserController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager, MailerInterface $mailer, TokenGeneratorInterface $tokenGenerator): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash the password
            $user->setPassword(
                $passwordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );
            // Generate an activation token
            $user->setActivationToken($tokenGenerator->generateToken());

            $entityManager->persist($user);
            $entityManager->flush();

            // Send activation email
            $email = (new Email())
                ->from('noreply@tonapp.com')
                ->to($user->getEmail())
                ->subject('Activation de votre compte')
                ->html('<p>Bonjour, veuillez activer votre compte en cliquant sur ce lien : 
                        <a href="http://localhost:8000/activate/' . $user->getActivationToken() . '">Activer mon compte</a></p>');

            $mailer->send($email);

            return $this->redirectToRoute('app_login');
        }

        return $this->render('user/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/activate/{token}', name: 'app_activate')]
    public function activate($token, EntityManagerInterface $entityManager): Response
    {
        $user = $entityManager->getRepository(User::class)->findOneBy(['activationToken' => $token]);

        if (!$user) {
            throw $this->createNotFoundException('Cet utilisateur n\'existe pas');
        }

        // Activate the account
        $user->setIsActive(true);
        $user->setActivationToken(null);
        $entityManager->flush();

        return $this->redirectToRoute('app_home');
    }

    private LoggerInterface $logger; // Declare a logger property

    public function __construct(LoggerInterface $logger) // Inject the logger
    {
        $this->logger = $logger;
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(Request $request, MailerInterface $mailer, TokenGeneratorInterface $tokenGenerator, EntityManagerInterface $entityManager): Response
    {
        $this->logger->info("Forgot password process initiated.");

        $form = $this->createForm(ForgotPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->logger->info("Forgot password form submitted.");

            $email = $form->get('email')->getData();
            $this->logger->info("Attempting to find user with email: " . $email);

            $user = $entityManager->getRepository(User::class)->findOneByEmail($email);

            if (!$user) {
                $this->logger->warning("User not found for email: " . $email);
                $this->addFlash('error', 'Utilisateur non trouvé.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $resetToken = $tokenGenerator->generateToken();
            $user->setResetToken($resetToken);
            $entityManager->flush();

            $this->logger->info("Generated reset token for user: " . $user->getEmail());

            $emailContent = '<p>Pour réinitialiser votre mot de passe, cliquez sur le lien suivant : 
                             <a href="' . $this->generateUrl('app_reset_password', ['token' => $resetToken], UrlGeneratorInterface::ABSOLUTE_URL) . '">Réinitialiser mon mot de passe</a></p>';

            $emailMessage = (new Email())
                ->from('noreply@tonapp.com')
                ->to($user->getEmail())
                ->subject('Réinitialisation de votre mot de passe')
                ->html($emailContent);

            $this->logger->info("Sending email to: " . $user->getEmail());

            try {
                $mailer->send($emailMessage);
                $this->logger->info("Email sent successfully to: " . $user->getEmail());
            } catch (\Exception $e) {
                $this->logger->error("Failed to send email: " . $e->getMessage());
                $this->addFlash('error', 'Une erreur est survenue lors de l\'envoi de l\'email.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $this->addFlash('success', 'Un email a été envoyé à votre adresse pour réinitialiser votre mot de passe.');
            $this->logger->info("Password reset email has been sent to: " . $user->getEmail());

            return $this->redirectToRoute('app_home');
        }

        return $this->render('user/forgot_password.html.twig', [
            'forgotPasswordForm' => $form->createView(),
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function resetPassword($token, Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        // Find the user by the reset token
        $user = $entityManager->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        if (!$user) {
            throw $this->createNotFoundException('Ce token est invalide.');
        }

        // Create the reset password form
        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash and update the new password
            $user->setPassword(
                $passwordHasher->hashPassword(
                    $user,
                    $form->get('newPassword')->getData()
                )
            );
            // Clear the reset token
            $user->setResetToken(null);
            $entityManager->flush();

            // Redirect to home page after resetting password
            return $this->redirectToRoute('app_home');
        }

        return $this->render('user/reset_password.html.twig', [
            'resetPasswordForm' => $form->createView(),
        ]);
    }



    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Log the request parameters
        error_log(print_r($request->request->all(), true)); // Log all request parameters

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('user/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }




    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This will be intercepted by the logout key on your firewall
        throw new \Exception('Don\'t forget to activate logout in security.yaml');
    }
}