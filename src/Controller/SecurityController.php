<?php

namespace App\Controller;

use App\Entity\ResetPassword;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\ResetPasswordRepository;
use App\Repository\UserRepository;
use App\Service\UploaderPicture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class SecurityController extends AbstractController
{

    public function __construct(private $formLoginAuthenticator)
    {
        
    }

    #[Route('/signup', name: 'signup')]
    public function signup(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, UserAuthenticatorInterface $userAuthenticator, MailerInterface $mailer, UploaderPicture $uploaderPicture): Response
    {
        $user = new User();
        $userForm = $this->createForm(UserType::class, $user);
        $userForm->handleRequest($request);

        if($userForm->isSubmitted() && $userForm->isValid()) {
            $hash = $passwordHasher->hashPassword($user, $user->getPassword());
            $user->setPassword($hash);

            //télécharger l'image de profil upload par user
            $picture = $userForm->get('pictureFile')->getData();
            //lancer le service

            //appliquer la photo à l'utilisateur
            $user->setPicture($uploaderPicture->uploadProfileImage($picture));

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Bienvenu sur Quora');


            //Envoie de l'email de bienvenu
            $welcomeEmail = new TemplatedEmail();
            $welcomeEmail->to($user->getEmail())
                        ->subject('Bienvenue sur Quora')
                        ->htmlTemplate('@email_templates/welcome.html.twig')
                        ->context(
                            ['username' => $user->getFirstname()]
                        );
            $mailer->send($welcomeEmail);

            // return $this->redirectToRoute('login');
            return $userAuthenticator->authenticateUser($user, $this->formLoginAuthenticator, $request);
        }
        return $this->render('security/signup.html.twig', ['form' => $userForm->createView()]);
    }

    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if($this->getUser()) {
            return $this->redirectToRoute('home');
        }
        
        $error = $authenticationUtils->getLastAuthenticationError();
        $username = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'error' => $error,
            'username' => $username
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout()
    {
        
    }

    #[Route('/reset-password-request', name: 'reset-password-request')]
    public function resetPasswordRequest(Request $request, UserRepository $userRepo, ResetPasswordRepository $resetPassowordRepo, EntityManagerInterface $em, MailerInterface $mailer, RateLimiterFactory $passwordRecoveryLimiter)
    {

        $limiter = $passwordRecoveryLimiter->create($request->getClientIp());
        
        $emailResetRequestForm = $this->createFormBuilder()
                                    ->add('email', EmailType::class, [
                                        'constraints' => [
                                            new NotBlank([
                                                'message' => 'Veuillez renseigner ce champs'
                                            ]),
                                            new Email([
                                                'message' => 'Veuillez entrer un email valide'
                                            ])
                                        ]
                                    ])
                                    ->getForm();

        $emailResetRequestForm->handleRequest($request);
        if($emailResetRequestForm->isSubmitted() && $emailResetRequestForm->isValid()) {

            //limiter : s'il consome 4 tentatives de reinitialiser le mdp on bloque
            if(!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Vous devez attendre 1 heure pour refaire une demande');
                return $this->redirectToRoute('login');
            }
            
            $emailUser = $emailResetRequestForm->get('email')->getData();
            $user = $userRepo->findOneBy(['email' => $emailUser]);

            if($user) {

                //vérification qu'il n'y ai pas d'ancienne demande
                $oldResetPassword = $resetPassowordRepo->findOneBy(['user' => $user]);
                if($oldResetPassword) {
                    // $resetPassowordRepo->remove($oldResetPassword, true);
                    $em->remove($oldResetPassword);
                    $em->flush();
                }

                //création du token
                $token = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(40))), 0, 20);
                $resetPassoword = new ResetPassword();
                $resetPassoword->setUser($user)
                                ->setExiredAt(new \DateTimeImmutable('+2 hours'))
                                ->setToken(sha1($token));
        
                $em->persist($resetPassoword);
                $em->flush();

                //Envoi du mail de réinitialisation
                $emailResetRequest = new TemplatedEmail();
                $emailResetRequest->to($emailUser)
                                ->subject('Demande de réinitialisation de mot de passe')
                                ->htmlTemplate('@email_templates/reset_password_request.html.twig')
                                ->context([
                                    'username' => $user->getFirstname(),
                                    'token' => $token
                                ]);

                $mailer->send($emailResetRequest);

            }

            $this->addFlash('success', 'Un email vous a été envoyé !');
            return $this->redirectToRoute('home');

        }

        return $this->render('security/reset_password_request.html.twig', [
            'form' => $emailResetRequestForm->createView()
        ]);
    }

    #[Route('/reset-password/{token}', name: 'reset-password')]
    public function resetPassword(string $token, ResetPasswordRepository $resetPasswordRepo, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher, Request $request, RateLimiterFactory $passwordRecoveryLimiter) 
    {
        //limiter : s'il consome 4 tentatives de token url on bloque
        $limiter = $passwordRecoveryLimiter->create($request->getClientIp());
        if(!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Vous devez attendre 1 heure pour refaire une demande');
            return $this->redirectToRoute('login');
        }

        $resetPassword = $resetPasswordRepo->findOneBy(['token' => sha1($token)]);

            if(!$resetPassword || $resetPassword->getExiredAt() < new \DateTime('now')) {
                if($resetPassword) {
                    $resetPasswordRepo->remove($resetPassword, true);
                }
                $this->addFlash('error', 'Votre demande n\'existe pas ou a exipré');
                return $this->redirectToRoute('login');
            }

        $passwordResetForm = $this->createFormBuilder()
                                    ->add('password', PasswordType::class, [
                                        'label' => 'Nouvea mot de passe',
                                        'constraints' => [
                                            new Length([
                                                'min' => 6,
                                                'minMessage' => 'Le mot de passe doit faire au moins 6 carractères'
                                            ]),
                                            new NotBlank([
                                                'message' => 'Veuillez renseigner ce champs'
                                            ])
                                        ]
                                    ])
                                    ->getForm();


        $passwordResetForm->handleRequest($request);

            if($passwordResetForm->isSubmitted() && $passwordResetForm->isValid()) {
                $newPassword = $passwordResetForm->get('password')->getData();
                $user = $resetPassword->getUser();

                $hash = $passwordHasher->hashPassword($user, $newPassword);
                $user->setPassword($hash);

                $em->remove($resetPassword);
                $em->flush();

                $this->addFlash('success', 'Votre mot de passe a bien été modifié !');
                return $this->redirectToRoute('login');
            }

        return $this->render('security/reset_password_form.html.twig', [
            'form' => $passwordResetForm->createView()
        ]);
    }

}
