<?php

namespace Packeton\Repository;

use Packeton\Entity\Package;
use Packeton\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * GruopRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class GroupRepository extends \Doctrine\ORM\EntityRepository
{
    /**
     * @param User $user
     * @param Package $package
     *
     * @return array
     */
    public function getAllowedVersionByPackage(User $user, Package $package)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select('acl.version')
            ->distinct()
            ->from(User::class, 'u')
            ->innerJoin('u.groups', 'g')
            ->innerJoin('g.aclPermissions', 'acl')
            ->innerJoin('acl.package', 'p')
            ->where('u.id = :uid')
            ->andWhere('p.id = :pid')
            ->setParameter('pid', $package->getId())
            ->setParameter('uid', $user->getId());

        $result = $qb->getQuery()->getResult();
        if ($result) {
            $result = \array_column($result, 'version');
        }

        return $result;
    }

    /**
     * @param User|UserInterface $user
     * @param bool $hydration
     *
     * @return Package[]
     */
    public function getAllowedPackagesForUser(?UserInterface $user, $hydration = true)
    {
        if (!$user instanceof User) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select('p.id')
            ->distinct()
            ->from(User::class, 'u')
            ->innerJoin('u.groups', 'g')
            ->innerJoin('g.aclPermissions', 'acl')
            ->innerJoin('acl.package', 'p')
            ->where($qb->expr()->eq('u.id', $user->getId()));

        $result = $qb->getQuery()->getResult();
        if ($hydration && $result) {
            $qb = $this->getEntityManager()->createQueryBuilder();
            $qb->select('p')
                ->from(Package::class, 'p')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', array_column($result, 'id'));

            return $qb->getQuery()->getResult();
        }

        return $result ? array_column($result, 'id') : [];
    }
}
