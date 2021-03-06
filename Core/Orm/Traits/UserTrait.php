<?php
//Last updated: 2019-04-18 11:46:33
namespace MillenniumFalcon\Core\Orm\Traits;

use Doctrine\DBAL\Connection;
use MillenniumFalcon\Core\Service\ModelService;
use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;
use Symfony\Component\Security\Core\User\UserInterface;

trait UserTrait
{
    /**
     * @param $pdo
     */
    static public function initData($pdo, $container)
    {
        $fullClass = ModelService::fullClass($pdo, 'DataGroup');
        $result = $fullClass::data($pdo);
        $dataGroup = array_map(function ($itm) {
            return $itm->getId();
        }, $result);

        $password = uniqid();
        $orm = new static($pdo);
        $orm->setTitle('weida');
        $orm->setPasswordInput($password);
        $orm->setName('Weida Xue');
        $orm->setEmail('luckyweida@gmail.com');
        $orm->setAccessibleSections(json_encode($dataGroup));

        $dir = $container->getParameter('kernel.project_dir') . '/vendor/pozoltd/millennium-falcon/Resources/views';
        $loader = $container->get('twig')->getLoader();
        $loader->addPath($dir);

        $messageBody = $container->get('twig')->render("cms/emails/install/email-welcome.html.twig", array(
            'orm' => $orm,
        ));

        $message = (new \Swift_Message())
            ->setSubject('CMS is ready - ' . date('d M Y@H:i'))
            ->setFrom(array(getenv('EMAIL_FROM')))
            ->setTo($orm->getEmail())
            ->setBcc(array(getenv('EMAIL_BCC')))
            ->setBody($messageBody, 'text/html');
        $container->get('mailer')->send($message);

        $orm->save();
    }
    
    /**
     * @return array|mixed
     */
    public function objAccessibleSections()
    {
        return $this->getAccessibleSections() ? json_decode($this->getAccessibleSections()) : [];

    }

    /**
     * @param bool $doubleCheck
     */
    public function save($doubleCheck = false)
    {
        if ($this->getPasswordInput()) {
            $encoder = new MessageDigestPasswordEncoder();
            $this->setPassword($encoder->encodePassword($this->getPasswordInput(), ''));
            $this->setPasswordInput(null);
        }
        parent::save($doubleCheck);
    }

    /**
     * The equality comparison should neither be done by referential equality
     * nor by comparing identities (i.e. getId() === getId()).
     *
     * However, you do not need to compare every attribute, but only those that
     * are relevant for assessing whether re-authentication is required.
     *
     * @return bool
     */
    public function isEqualTo(UserInterface $user)
    {
        $fullClass = ModelService::fullClass($this->getPdo(), 'User');
        if (!($user instanceof $fullClass)) {
            return false;
        }

        if ($this->getPassword() !== $user->getPassword()) {

            return false;
        }

        if ($this->getSalt() !== $user->getSalt()) {
            return false;
        }

        if ($this->getUsername() !== $user->getUsername()) {
            return false;
        }

        return true;
    }

    /**
     * Returns the roles granted to the user.
     *
     * <code>
     * public function getRoles()
     * {
     *     return array('ROLE_USER');
     * }
     * </code>
     *
     * Alternatively, the roles might be stored on a ``roles`` property,
     * and populated in any number of different ways when the user object
     * is created.
     *
     * @return (Role|string)[] The user roles
     */
    public function getRoles()
    {
        return array('ROLE_ADMIN');
    }

    /**
     * Returns the salt that was originally used to encode the password.
     *
     * This can return null if the password was not encoded using a salt.
     *
     * @return string|null The salt
     */
    public function getSalt()
    {
        return '';
    }

    /**
     * Returns the username used to authenticate the user.
     *
     * @return string The username
     */
    public function getUsername()
    {
        return $this->getTitle();
    }

    /**
     * Removes sensitive data from the user.
     *
     * This is important if, at any given point, sensitive information like
     * the plain-text password is stored on this object.
     */
    public function eraseCredentials()
    {
        return $this;
    }

    /**
     * String representation of object
     * @link http://php.net/manual/en/serializable.serialize.php
     * @return string the string representation of the object or null
     * @since 5.1.0
     */
    public function serialize()
    {

        $fields = array_keys(static::getFields());

        $obj = new \stdClass();
        foreach ($fields as $field) {
            $getMethod = "get" . ucfirst($field);
            $obj->{$field} = $this->$getMethod();
        }
        return serialize($obj);
    }

    /**
     * Constructs the object
     * @link http://php.net/manual/en/serializable.unserialize.php
     * @param string $serialized <p>
     * The string representation of the object.
     * </p>
     * @return void
     * @since 5.1.0
     */
    public function unserialize($serialized)
    {
        $obj = unserialize($serialized);
        foreach ($obj as $idx => $itm) {
            $setMethod = "set" . ucfirst($idx);
            $this->$setMethod($itm);
        }

        $conn = \Doctrine\DBAL\DriverManager::getConnection(array(
            'url' => getenv('DATABASE_URL'),
        ), new \Doctrine\DBAL\Configuration());
        $this->setPdo($conn);
    }
}