<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Form\LoginForm;
use AppBundle\Entity\User;
use AppBundle\Entity\UserLog;
use Symfony\Component\Form\FormError;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

class LoginController extends Controller
{
    /**
     * @Route("/login/", name="login_page")
     * @Method("GET")
     */
    public function getLoginPageAction()
    {
        $loginForm = $this->createForm(LoginForm::class);
        return $this->render('default/login.html.twig',
            array(
                'login_form' => $loginForm->createView(),
                'recent_logs' => $this->getRecentLogs()
            )
        );
    }

    /**
     * @Route("/login/", name="login")
     * @Method("POST")
     */
    public function loginAction(Request $request)
    {
        $user = new User();
        $form = $this->createForm(LoginForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $postData = $request->request->get('login_form');
            $userRepository = $this->getDoctrine()->getRepository('AppBundle:User');
            $existedUser = $userRepository->findOneBy(
                array(
                    'login' => $postData['login']
                )
            );

            if (!$existedUser) {
                $user = $this->createNewUser($postData['login'], $postData['password']);

                $this->loginUser($user);

                return $this->redirectToRoute('profile');
            } else {
                if ($this->checkPassword($existedUser, $postData['password'])) {

                    $this->loginUser($existedUser);

                    return $this->redirectToRoute('profile');
                } else {
                    $form->addError(new FormError('Неправильный пароль'));
                }
            }

        }

        return $this->render('default/login.html.twig',
            array(
                'login_form' => $form->createView(),
                'recent_logs' => $this->getRecentLogs()
            )
        );
    }

    /**
     * @Route("/profile/", name="profile")
     * @Method("GET")
     */
    public function getProfileAction()
    {
        $session = $this->container->get('session');

        if (!$session->isStarted()) {
            $session = new Session();
            $session->start();
        }

        $userId = $session->get('user_id');

        if ($userId > 0) {
            $logs = $this->getUserLog($this->getUserById($userId));
            return $this->render('default/profile.html.twig',
                array(
                    'logs' => $logs
                )
            );
        } else {
            return $this->redirectToRoute('login');
        }
    }

    /**
     * @Route("/get-browser-statistics/", name="browser-statistics", condition="request.isXmlHttpRequest()")
     * @Method("POST")
     */
    public function getBrowserStatisticsAction(Request $request)
    {
        $user = new User();
        $form = $this->createForm(LoginForm::class, $user);
        $form->handleRequest($request);

        $responseData = array();

        if ($form->isSubmitted() && $form->isValid()) {
            $postData = $request->request->get('login_form');

            $user = $this->getUserByLogin($postData['login']);

            if ($this->checkPassword($user, $postData['password'])) {
                $browserLog = $this->getBrowserLog($_SERVER['HTTP_USER_AGENT']);
                foreach ($browserLog as $log) {
                    $data = array('login' => $log->getUser()->getLogin(), 'time' => $log->getEntryTime());
                    $responseData[] = $data;
                }
            }
        }
        return new JsonResponse($responseData);
    }

    /**
     * Add new log record
     * 
     * @param AppBundle\Entity\User $user
     *
     * @return void
     */
    private function writeUserLog($user)
    {
        $userLog = new UserLog();
        $userLog->setEntryTime(new \DateTime(date("Y-m-d H:i:s")));
        $userLog->setBrowser($_SERVER['HTTP_USER_AGENT']);
        $userLog->setIp($_SERVER['REMOTE_ADDR']);
        $userLog->setUser($user);

        $em = $this->getDoctrine()->getManager();
        $em->persist($userLog);
        $em->flush();
    }

    /**
     * Create new User
     * 
     * @param string $login
     * @param string $password
     *
     * @return AppBundle\Entity\User
     */
    private function createNewUser($login, $password)
    {
        $factory = $this->get('security.encoder_factory');

        $user = new User();

        $encoder = $factory->getEncoder($user);
        $user->setSalt(md5(time()));
        $password = $encoder->encodePassword($password, $user->getSalt());
        $user->setLogin($login);
        $user->setPassword($password);

        $em = $this->getDoctrine()->getManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Check login/password
     * 
     * @param AppBundle\Entity\User $user
     * @param string $password
     *
     * @return boolean
     */
    private function checkPassword($user, $password)
    {
        $factory = $this->get('security.encoder_factory');
        $encoder = $factory->getEncoder($user);

        if ($encoder->isPasswordValid($user->getPassword(), $password, $user->getSalt())) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get log for user
     * 
     * @param AppBundle\Entity\User $user
     *
     * @return AppBundle\Entity\UserLog
     */
    private function getUserLog($user)
    {
        $userLogRepository = $this->getDoctrine()->getRepository('AppBundle:UserLog');

        $log = $userLogRepository->findBy(
            array('user' => $user),
            array('entryTime' => 'DESC')
        );

        return $log;
    }

    /**
     * Get log for browser
     * 
     * @param string $browser
     *
     * @return array
     */
    private function getBrowserLog($browser)
    {
        $userLogRepository = $this->getDoctrine()->getRepository('AppBundle:UserLog');

        $log = $userLogRepository->findBy(
            array('browser' => $browser),
            array('entryTime' => 'DESC')
        );

        return $log;
    }

    /**
     * Set userId to session
     * 
     * @param int $userId
     *
     * @return void
     */
    private function setUserLogged($userId)
    {
        $session = $this->container->get('session');

        if (!$session->isStarted()) {
            $session = new Session();
            $session->start();
        }

        $session->set('user_id', $userId);
    }

    /**
     * Get user by id
     * 
     * @param int $userId
     *
     * @return mixed
     */
    private function getUserById($userId)
    {
        $userRepository = $this->getDoctrine()->getRepository('AppBundle:User');
        $user = $userRepository->find($userId);

        if (!$user) {
            throw $this->createNotFoundException('There is no user with that Id');
        } else {
            return $user;
        }
    }

    /**
     * Get user by login
     * 
     * @param string $userLogin
     *
     * @return mixed
     */
    private function getUserByLogin($userLogin)
    {
        $userRepository = $this->getDoctrine()->getRepository('AppBundle:User');
        $user = $userRepository->findOneBy(
            array(
                'login' => $userLogin
            )
        );

        if (!$user) {
            throw $this->createNotFoundException('There is no user with that Login');
        } else {
            return $user;
        }
    }

    /**
     * Login user to system
     * 
     * @param AppBundle\Entity\User $user
     *
     * @return void
     */
    private function loginUser($user) {
        $this->setUserLogged($user->getId());
        $this->writeUserLog($user);
        $this->container->get('session')->getFlashBag()->add(
            'notice',
            'Здравствуйте, Вы успешно вошли!'
        );
    }

    /**
     * Get most recent logs to system
     * 
     * @param int $count
     *
     * @return array
     */
    private function getRecentLogs($count = 5) {

        $userLogRepository = $this->getDoctrine()->getRepository('AppBundle:UserLog');

        return $userLogRepository->findBy(
            array(),
            array('entryTime' => 'DESC'),
            $count
        );
    }
}
