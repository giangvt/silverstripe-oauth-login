<?php

namespace Bigfork\SilverStripeOAuth\Client\Handler;

use Bigfork\SilverStripeOAuth\Client\Extension\MemberExtension;
use Bigfork\SilverStripeOAuth\Client\Factory\MemberMapperFactory;
use Bigfork\SilverStripeOAuth\Client\Mapper\MemberMapperInterface;
use Bigfork\SilverStripeOAuth\Client\Model\Passport;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\IdentityStore;
use SilverStripe\Security\Member;
use SilverStripe\Security\Group;
use SilverStripe\Security\Security;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;


class LoginTokenHandler implements TokenHandler
{
    /**
     * {@inheritdoc}
     */
    public function handleToken(AccessToken $token, AbstractProvider $provider)
    {
        try {
            // Find or create a member from the token
            $member = $this->findOrCreateMember($token, $provider);
        } catch (ValidationException $e) {
            return Security::permissionFailure(null, $e->getMessage());
        }

        // Check whether the member can log in before we proceed
        $result = $member->validateCanLogin();
        if (!$result->isValid()) {
            return Security::permissionFailure(null, implode('; ', $result->getMessages()));
        }

        // Log the member in
        $identityStore = Injector::inst()->get(IdentityStore::class);
        $identityStore->logIn($member);

        return null;
    }

    /**
     * Find or create a member from the given access token
     *
     * @param AccessToken $token
     * @param AbstractProvider $provider
     * @return Member
     * @throws ValidationException
     */
    protected function findOrCreateMember(AccessToken $token, AbstractProvider $provider)
    {
        $user = $provider->getResourceOwner($token);

        /** @var Passport $passport */
        $passport = Passport::get()->filter([
            'Identifier' => $user->getId()
        ])->first();

        if (!$passport) {
            // Create the new member
            $member = $this->createMember($token, $provider);

            // Create the group member            
            $query = new SQLSelect();
            $group = Group::get()->where("\"Code\" = 'administrators'")->column('ID');
            $groupID = $group[0];

            $insert = SQLInsert::create('"Group_Members"');
            $insert->addRow(['"GroupID"' => $groupID, '"MemberID"' => $member->ID]);
            $insert->execute();

            // Create a passport for the new member
            $passport = Passport::create()->update([
                'Identifier' => $user->getId(),
                'MemberID' => $member->ID
            ]);
            $passport->write();
        }

        return $passport->Member();
    }

    /**
     * Create a member from the given token
     *
     * @param AccessToken $token
     * @param AbstractProvider $provider
     * @return Member
     * @throws ValidationException
     */
    protected function createMember(AccessToken $token, AbstractProvider $provider)
    {
        $session = $this->getSession();
        $providerName = $session->get('oauth2.provider');
        $user = $provider->getResourceOwner($token);

        /** @var Member|MemberExtension $member */
        $member = Member::create();
        $member = $this->getMapper($providerName)->map($member, $user);
        $member->OAuthSource = $providerName;
        $member->write();

        return $member;
    }

    /**
     * @param string $providerName
     * @return MemberMapperInterface
     */
    protected function getMapper($providerName)
    {
        return Injector::inst()->get(MemberMapperFactory::class)->createMapper($providerName);
    }

    /**
     * @return Session
     */
    protected function getSession()
    {
        if (Controller::has_curr()) {
            return Controller::curr()->getRequest()->getSession();
        }

        return Injector::inst()->create(Session::class, isset($_SESSION) ? $_SESSION : []);
    }
}
