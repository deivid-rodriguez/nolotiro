<?php

/**
 * @author Dani Remeseiro
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 *
 */
class UserController extends Zend_Controller_Action {


    public function init() {

        $this->lang = $this->view->lang = $this->_helper->checklang->check();
        $this->location = $this->_helper->checklocation->check();
        $this->check_messages = $this->_helper->checkMessages;
        $this->notifications = $this->_helper->Notifications;

        //check if user is locked
        $locked = $this->_helper->checkLockedUser->check();
        if ($locked == 1) {
            $this->_redirect('/' . $this->view->lang . '/auth/logout');
        }
    }


    public function indexAction() {
        //by now just redir to /
        $this->_redirect('/');
    }


    /**
     * register - register a new user into the nolotiro database
     */
    public function registerAction() {

        //if the user is logged already redir to home
        $auth = Zend_Auth::getInstance ();
        if ($auth->hasIdentity()) {
            $this->_redirect('/' . $this->lang . '/woeid/' . $this->location . '/give');
        }

        $request = $this->getRequest();
        $form = $this->_getUserRegisterForm();

        if ($this->getRequest()->isPost()) {

            if ($form->isValid($request->getPost())) {

                $formulario = $form->getValues();

                //check 2 passwords matches
                $checkpasswords = ($formulario['password1'] == $formulario['password2'] );

                if ($checkpasswords == FALSE) {
                    $view = $this->initView();
                    $view->error .= $this->view->translate('The  passwords entered do not match.');
                }

                $model = new Model_User();

                //check user email and nick if exists
                $checkemail = $model->checkEmail($formulario ['email']);
                $checkuser = $model->checkUsername($formulario ['username']);

                //not allow to use the email as username
                if ($formulario['email'] == $formulario['username']) {

                    $view = $this->initView();
                    $view->error = $this->view->translate('You can not use your email as username. Please,
                        choose other username');
                }

                if ($checkemail !== NULL) {
                    $view = $this->initView();
                    $view->error = $this->view->translate('This email is taken. Please, try again.');
                }

                if ($checkuser !== NULL) {
                    $view = $this->initView();
                    $view->error = $this->view->translate('This username is taken. Please, try again.');
                }

                if ($checkemail == NULL and $checkuser == NULL and $checkpasswords == TRUE) {

                    // success: insert the new user on ddbb
                    $data ['password'] = md5($formulario ['password1']);
                    $data ['email'] = $formulario ['email'];
                    $data ['username'] = $formulario ['username'];
                    $data ['created'] = date ( 'Y-m-d H:i:s' );
                    $data ['token'] = md5 ( uniqid ( rand (), 1 ) );
                    $model->save($data);

                    //once token generated by model save, now we need it to send to the user by email
                    $token = $model->getToken($formulario['email']);

                    //now lets send the validation token by email to confirm the user email
                    $hostname = 'http://' . $this->getRequest()->getHttpHost();

                    $mail = new Zend_Mail ( );
                    $mail->setBodyHtml($this->view->translate('Please, click on this url to finish your register process:<br />')
                            . '<a href="' . $hostname . $this->view->translate('/en/user/validate/t/') . $token . '">' . $hostname . $this->view->translate('/en/user/validate/t/') . $token . '</a>' .
                            '<br /><br />---------<br />' . utf8_decode($this->view->translate('The nolotiro.org team.')));
                    $mail->setFrom('noreply@nolotiro.org', 'nolotiro.org');

                    $mail->addTo($formulario['email']);
                    $mail->setSubject($formulario ['username'] . $this->view->translate(', confirm your email'));
                    $mail->send();

                    $this->_helper->_flashMessenger->addMessage($this->view->translate('Check your inbox email to finish the register process'));

                    $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
                }
            }
        }
        // assign the form to the view
        $this->view->form = $form;
    }


    public function profileAction() {

        $lang = $this->view->lang;
        $userId = $this->view->userId = $this->getRequest()->getParam('id');

        if (!$userId) {
            $this->_helper->_flashMessenger->addMessage($this->view->translate('This user does not exist'));
            $this->_redirect('/' . $lang . '/woeid/' . $this->location . '/give');
        }

        $modelU = new Model_User();
        $user = $modelU->fetchUser($userId);
        $userName = $this->view->userName = $user['username'];

        $this->view->friendlist = $modelU->fetchUserFriends($userId);

        $send_link = '/' . $lang . '/message/create/id_user_to/' . $userId;
        $this->view->sendmessage_tab = '
            <a class="world_link" href="' . $send_link . '">' .
              $this->view->translate('send message to') . ' ' . $userName . '
              <img src="/images/email_send.png" alt="send a message"/></a>';

        $auth = Zend_Auth::getInstance ();
        if ($auth->hasIdentity()) {
            $this->view->myUserId = $auth->getIdentity()->id;

            //check if this user is friend or not to paint proper link
            if ($modelU->isMyFriend($this->view->myUserId, $userId)) {
                $this->view->friend_link = '
                  <a class="world_link"
                     href="/' . $lang . '/user/addfriend/id/' . $userId . '">
                       ' . $this->view->translate('add') .  ' ' .
                       $userName .  ' ' .
                       $this->view->translate('to your friends list'). '
                    <img src="/images/friend_add.png" alt="Add friend"/></a>';
            } else {
                $this->view->friend_link = '
                  <a class="world_link"
                     href="/' . $lang . '/user/deletefriend/id/' . $userId . '">
                       ' . $this->view->translate('remove') . ' ' .
                       $userName . ' ' .
                       $this->view->translate('from your friends list'). '
                    <img src="/images/friend_delete.png" alt="Delete friend"/></a>';
            }
        } else
            $this->view->myUserId = null;

        //if user is admin show block user link
        $this->userRole = $this->_helper->checkUserRole->check();

        //only admins have access to this action
        if ($this->userRole == 1) {
            $this->view->userLockLink =
                $this->view->translate('You are admin, you can ') . '
                  <a href="/' . $lang . '/user/lock/id/' . $userId . '">
                    ' . $this->view->translate('lock this user') . '</a>';
        }
    }


    public function friendsAction() {
        $request = $this->getRequest();
        $id = (int) $this->_request->getParam('id');

        $model = new Model_User();
        $this->view->friendlist = $model->fetchUserFriends($id);
    }


    public function deletefriendAction() {
        //TODO make sure check user is auth to delete this friend!!!!!!!
        $this->_helper->viewRenderer->setNoRender(true);
         //check if user logged in
        $auth = Zend_Auth::getInstance ();

        $request = $this->getRequest();
        $id_friend = (int) $this->_request->getParam('id');

         $userM = new Model_User();
         $friendName = $userM->fetchUser($id_friend)->username;


         if ($auth->hasIdentity() ) {

             $id_user = $auth->getIdentity()->id;

             $checkFriendship = $userM->checkIfisMyFriend($id_user, $id_friend);

             //good bye my friend  :-(
             $userM->deleteUserFriend($id_user, $id_friend);


            $this->_helper->_flashMessenger->addMessage($this->view->translate('User successfully removed from your friends list'));
            $this->_redirect('/' . $this->lang . '/profile/' . $friendName );
            return;

        } else {

            $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
            $this->_redirect('/' . $this->lang . '/woeid/' . $this->location . '/give');
            return;
        }
    }


    public function addfriendAction(){

        $this->_helper->viewRenderer->setNoRender(true);
         //check if user logged in
        $auth = Zend_Auth::getInstance ();

        $request = $this->getRequest();
        $id_friend = (int) $this->_request->getParam('id');

        //TODO : check if the user friend exists before insert in db
        $userM = new Model_User();
        $friendName = $userM->fetchUser($id_friend)->username;

         if ($auth->hasIdentity() && $friendName == TRUE ) {

             $id_user = $auth->getIdentity()->id;

             $userM->addUserFriend($id_user, $id_friend);


            $this->_helper->_flashMessenger->addMessage($this->view->translate('User successfully added to your friends list'));
            $this->_redirect('/' . $this->lang . '/profile/' . $friendName );
            return;

        } else {

            $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
            $this->_redirect('/' . $this->lang . '/woeid/' . $this->location . '/give');
            return;
        }


    }


    /**
     *
     * @return Form_UserRegister
     */
    protected function _getUserRegisterForm() {
        require_once APPLICATION_PATH . '/forms/UserRegister.php';
        $form = new Form_UserRegister ( );
        return $form;
    }


    /**
     * forgot - sends (regenerates) a new token to the user
     *
     */
    public function forgotAction() {

        //if the user is logged already redir to home
        $auth = Zend_Auth::getInstance ();
        if ($auth->hasIdentity()) {
            $this->_redirect('/' . $this->lang . '/woeid/' . $this->location . '/give');
        }

        $request = $this->getRequest();
        $form = $this->_getUserForgotForm();

        if ($this->getRequest()->isPost()) {

            if ($form->isValid($request->getPost())) {

                // collect the data from the form
                $f = new Zend_Filter_StripTags ( );
                $email = $f->filter($this->_request->getPost('email'));

                $model = new Model_User();
                $mailcheck = $model->checkEmail($email);

                if ($mailcheck == NULL) {
                    // failure: email does not exists on ddbb
                    $view = $this->initView();
                    $view->error = $this->view->translate('This email is not in our database. Please, try again.');
                } else { // success: the email exists , so lets change the password and send to user by mail
                    //Zend_Debug::dump($mailcheck->toArray());
                    $mailcheck = $mailcheck->toArray();


                    //regenerate the token
                    $mailcheck['token'] = md5(uniqid(rand(), 1));
                    // update the user with this token
                    $model->update($mailcheck);



                    //lets send the new token
                    $hostname = 'http://' . $this->getRequest()->getHttpHost();


                    $mail = new Zend_Mail ( );
                    $mail->setBodyHtml($this->view->translate('Somebody , probably you, wants to restore your nolotiro access. Click on this url to restore your nolotiro account:') . '<br />'
                            . '<a href="' . $hostname . '/' . $this->view->lang . '/user/validate/t/' . $mailcheck['token'] . ' "> ' . $hostname . '/' . $this->view->lang . '/user/validate/t/' . $mailcheck['token'] . '</a>' .
                            '<br /><br />' .
                            $this->view->translate('Otherwise, ignore this message.') .
                            '<br />__<br />' . utf8_decode($this->view->translate('The nolotiro.org team.')));

                    $mail->setFrom('noreply@nolotiro.org', 'nolotiro.org');

                    $mail->addTo($mailcheck ['email']);
                    $mail->setSubject(utf8_decode($this->view->translate('Restore your nolotiro.org account')));
                    $mail->send();

                    $this->_helper->_flashMessenger->addMessage($this->view->translate('Check your inbox email to restore your nolotiro.org account'));
                    $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
                }
            }
        }
        // assign the form to the view
        $this->view->form = $form;
    }


    /**
     * @abstract generate a text plain random password
     * remember it's no encrypted !
     * @return string (7) $pass
     */
    protected function _generatePassword() {
        $salt = "abcdefghjkmnpqrstuvwxyz123456789";
        mt_srand((double) microtime () * 1000000);
        $i = 0;
        while ($i <= 6) {
            $num = mt_rand() % 33;
            $pass .= substr($salt, $num, 1);
            $i++;
        }

        return $pass;
    }


    /**
     *
     * @return Form_UserForgotForm
     */
    protected function _getUserForgotForm() {
        require_once APPLICATION_PATH . '/forms/UserForgot.php';
        $form = new Form_UserForgot ( );
        return $form;
    }


    /**
     * Validate - check the token generated  sent by mail by registerAction, then redirect to user edit profile
     * @param t
     *
     */
    public function validateAction() {

        //http://nolotiro.com/es/auth/validate/t/1232452345234
        $this->_helper->viewRenderer->setNoRender(true);
        $token = $this->_request->getParam('t'); //the token

        if (!is_null($token)) {

            //lets check this token against ddbb
            $model = new Model_User();
            $validatetoken = $model->validateToken($token);


            if ($validatetoken !== NULL) {

                $validatetoken = $validatetoken->toArray();
                //first kill previous session or data from client
                //kill the user logged in (if exists)
                Zend_Auth::getInstance ()->clearIdentity();
                $this->session->logged_in = false;
                $this->session->username = false;

                $data ['active'] = '1';
                $data ['id'] = $validatetoken ['id'];

                //reset the token
                $data['token'] = NULL;
                //update token user in ddbb
                $model->update($data);


                //update the auth data stored
                $data = $model->fetchUser($validatetoken ['id']);
                $auth = Zend_Auth::getInstance ();
                $auth->getStorage()->write((object) $data);

                $this->_helper->_flashMessenger->addMessage($this->view->translate('Welcome') . ' ' . $data['username']);
                $this->_redirect('/' . $this->view->lang . '/user/edit/id/' . $data->id );
            } else {

                $this->_helper->_flashMessenger->addMessage($this->view->translate('Sorry, register url no valid or expired.'));
                $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
                return;
            }
        } else {
            $this->_helper->_flashMessenger->addMessage($this->view->translate('Sorry, register url no valid or expired.'));
            $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
            return;
        }
    }


    public function editAction() {

        $this->view->headTitle()->append($this->view->translate('Edit your profile'));

        $id = $this->view->id = (int) $this->getRequest()->getParam('id');

        $auth = Zend_Auth::getInstance ();

        if (!$auth->getIdentity()->id) {
            $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
            $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
            return;
        }

        $model = new Model_User();
        $user = $model->fetchUser($id)->id;

        if (($auth->getIdentity()->id == $user)) { //if is the user profile owner lets edit
            require_once APPLICATION_PATH . '/forms/UserEdit.php';
            $form = new Form_UserEdit ( );
            $form->submit->setLabel('Save profile');
            $this->view->form = $form;


            if ($this->getRequest()->isPost()) {
                $formData = $this->getRequest()->getPost();
                if ($form->isValid($formData)) {

                    //chekusername if exists, dont let change it
                    $checkuser = $model->checkUsername($form->getValue('username'));
                    if (!is_null($checkuser) and ($checkuser['username'] != $auth->getIdentity()->username)) {
                        $this->view = $this->initView();
                        $this->view->error = $this->view->translate('This username is taken. Please choose another one.');
                        return;
                    }


                    $data['id'] = $id;
                    $data['username'] = $form->getValue('username');


                    if ($form->getValue('password')) {
                        $data['password'] = md5(trim($form->getValue('password')));
                    }


                    $model->update($data);

                    //update the auth data stored
                    $auth = Zend_Auth::getInstance ();
                    $auth->getStorage()->write((object) $data);

                    $this->_helper->_flashMessenger->addMessage($this->view->translate('Your profile was edited succesfully!'));
                    $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
                    return;
                } else {
                    $form->populate($formData);
                }
            } else {
                $id = $this->_getParam('id', 0);
                if ($id > 0) {
                    $user = new Model_User();
                    $form->populate($user->fetchUser($id)->toArray());
                }
            }
        } else {
            $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
            $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
            return;
        }
    }


    public function deleteAction() {
        $this->view->headTitle()->append($this->view->translate('Delete your profile'));

        $id = (int) $this->getRequest()->getParam('id');

        $auth = Zend_Auth::getInstance ();

        if (!$auth->getIdentity()->id) {
            $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
            $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
            return;
        }

        $model = new Model_User();
        $user = $model->fetchUser($id)->id;

        if (($auth->getIdentity()->id == $user)) { //if is the user profile owner lets delete it
            if ($this->getRequest()->isPost()) {
                $del = $this->getRequest()->getPost('del');
                if ($del == 'Yes') {
                    // delete user
                    $model->deleteUser($id);
                    // logout
                    $this->_helper->_flashMessenger->addMessage($this->view->translate('Your account has been deleted.'));
                    $this->_redirect('/' . $this->view->lang . '/auth/logout');
                    return;
                } else {
                    $this->_helper->_flashMessenger->addMessage($this->view->translate('Nice to hear that :-)'));
                    $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
                    return;
                }
            } else {
                $id = $this->_getParam('id', 0);
            }
        } else {

            $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
            $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
            return;
        }
    }


    public function lockAction(){

            $id = (int) $this->getRequest()->getParam('id');
            $this->view->userRole = $this->_helper->checkUserRole->check();

            //only admins have access to this action
            if ($this->view->userRole == 1) {

                $modelUser = new Model_User();
                $this->view->userToLock =  $modelUser->fetchUser($id)->username;

                if($this->view->userToLock == null){ //the user does not exists
                        $this->_helper->_flashMessenger->addMessage($this->view->translate('This user does not exists'));
                        $this->_redirect('/' . $this->lang . '/woeid/' . $this->location . '/give');
                }

                if ($this->getRequest()->isPost()) {
                    $lock = $this->getRequest()->getPost('lock');
                    if ($lock == 'Yes') {
                        //bye bye troll
                        $data['locked'] = 1;
                        $data['id'] = $id;
                        $modelUser->update($data);

                        $this->_helper->_flashMessenger->addMessage($this->view->translate('User locked successfully.'));
                        $this->_redirect('/' . $this->view->lang . '/woeid/' . $this->location . '/give');
                        return;
                    }
                }

            }
            else {
                $this->_helper->_flashMessenger->addMessage($this->view->translate('You are not allowed to view this page'));
                $this->_redirect('/' . $this->lang . '/woeid/' . $this->location . '/give');
                return;
            }

    }

}
