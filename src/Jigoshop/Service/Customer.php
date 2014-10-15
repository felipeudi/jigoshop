<?php

namespace Jigoshop\Service;

use Jigoshop\Entity\Customer as Entity;
use Jigoshop\Entity\EntityInterface;
use Jigoshop\Factory\Customer as Factory;
use WPAL\Wordpress;

/**
 * Customer service.
 *
 * @package Jigoshop\Service
 */
class Customer implements CustomerServiceInterface
{
	/** @var Wordpress */
	private $wp;
	/** @var Factory */
	private $factory;

	public function __construct(Wordpress $wp, Factory $factory)
	{
		$this->wp = $wp;
		$this->factory = $factory;
	}

	/**
	 * Returns currently logged in customer.
	 *
	 * @return Entity Current customer entity.
	 */
	public function getCurrent()
	{
		$user = $this->wp->wpGetCurrentUser();
		return $this->factory->fetch($user);
	}

	/**
	 * Finds single user with specified ID.
	 *
	 * @param $id int Customer ID.
	 * @return Entity Customer for selected ID.
	 */
	public function find($id)
	{
		$user = $this->wp->getUserData($id);
		return $this->factory->fetch($user);
	}

	/**
	 * Finds and fetches all available WordPress users.
	 *
	 * @return array List of all available users.
	 */
	public function findAll()
	{
		$guest = new Entity\Guest();
		$customers = array(
			$guest->getId() => $guest,
		);

		$users = $this->wp->getUsers();
		foreach ($users as $user) {
			$customers[$user->ID] = $this->factory->fetch($user);
		}

		return $customers;
	}

	/**
	 * Saves product to database.
	 *
	 * @param EntityInterface $object Customer to save.
	 * @throws Exception
	 */
	public function save(EntityInterface $object)
	{
		if (!($object instanceof Entity)) {
			throw new Exception('Trying to save not a customer!');
		}

		// TODO: Implement save() method.
	}

	/**
	 * Finds item for specified WordPress post.
	 *
	 * @param $post \WP_Post WordPress post.
	 * @return EntityInterface Item found.
	 */
	public function findForPost($post)
	{
		// TODO: Implement findForPost() method.
	}

	/**
	 * Finds items specified using WordPress query.
	 *
	 * @param $query \WP_Query WordPress query.
	 * @return array Collection of found items.
	 */
	public function findByQuery($query)
	{
		// TODO: Implement findByQuery() method.
	}
}
