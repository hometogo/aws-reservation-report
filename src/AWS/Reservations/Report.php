<?php

namespace AWS\Reservations;

class Report
{

    /**
     * @var array
     */
    private $instances;

    /**
     * @var array
     */
    private $reservations;

    /**
     * @var array
     */
    private $groups;

    /**
     * @param array $instances
     * @param array $reservations
     * @param array $groups
     */
    public function __construct(array $instances, array $reservations, array $groups)
    {
        $this->instances = $instances;
        $this->reservations = $reservations;
        $this->groups = $groups;
    }

    /**
     * @param Reservation[]|ResourceList $list
     * @return array
     */
    protected function buildExpirationMap($list)
    {
        $out = [];

        foreach ($list as $item) {
            $out[$item->getGroupId()] = $item->getExpires()->format('Y-m-d');
        }

        return $out;
    }

    /**
     * @param null|string $type
     * @param null|bool $covered
     * @return array
     */
    public function generate($type = null, $covered = null)
    {
        $out = [
            'header' => [
                'Group',
                'Type',
                'AZ',
                'Running Count',
                'Total Reserved'
            ],
            'body' => []
        ];

        $instances = $this->createInstances($type);
        $reservations = $this->createReservations($type);
        $map = $this->buildExpirationMap($reservations);

        $dates = array_unique(array_values($map));
        sort($dates);
        $out['header'] = array_merge($out['header'], $dates);

        $instances->match($reservations);
        ($covered !== null) && $instances->filterCovered($covered);

        foreach ($instances as $instance) {
            if ($instance instanceof Resource) {
                $expirations = array_fill_keys($dates, '');
                foreach ($instance->getMatchedCounts() as $groupId => $matchedCount) {
                    $expirations[$map[$groupId]] = $matchedCount;
                }

                $out['body'][] = array_merge(
                    [
                        $instance->getName(),
                        $instance->getType(),
                        $instance->getAvailabilityZone(),
                        $instance->getCount(),
                        $instance->getMatchedCount()
                    ],
                    array_values($expirations)
                );
            }
        }

        foreach ($reservations as $reservation) {
            if ($reservation instanceof Reservation) {
                $expirations = array_fill_keys($dates, '');
                $expirations[$reservation->getExpires()->format('Y-m-d')] = $reservation->getCount();

                $out['body'][] = array_merge(
                    [
                        '',
                        $reservation->getType(),
                        $reservation->getAvailabilityZone(),
                        0,
                        $reservation->getCount()
                    ],
                    array_values($expirations)
                );
            }
        }

        return $out;
    }

    /**
     * @param $type
     * @return ResourceList
     */
    private function createInstances($type = null)
    {
        $instances = (new InstancesParser($this->groups))->parse($this->instances);
        $type && $instances->filterType($type);
        $instances->sort();
        return $instances;
    }

    /**
     * @param $type
     * @return ResourceList
     */
    private function createReservations($type = null)
    {
        $reservations = (new ReservationsParser())->parse($this->reservations);
        $type && $reservations->filterType($type);
        $reservations->sort();
        return $reservations;
    }

    /**
     * @return int
     */
    public function getUnusedReservations()
    {
        $instances = $this->createInstances();
        $reservations = $this->createReservations();
        $instances->match($reservations);
        return $reservations->count();
    }
}
