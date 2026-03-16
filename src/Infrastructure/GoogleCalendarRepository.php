<?php

namespace GastosNaia\Infrastructure;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;

class GoogleCalendarRepository
{
    private Calendar $calendarService;

    public function __construct(Client $client)
    {
        // Añadir scope de Calendar si no está ya
        $client->addScope(Calendar::CALENDAR);
        $this->calendarService = new Calendar($client);
    }

    /**
     * Obtiene los eventos de un mes concreto.
     */
    public function getEvents(string $calendarId, int $year, int $month): array
    {
        $timeMin = (new \DateTime("$year-$month-01T00:00:00"))->format(\DateTime::RFC3339);
        $lastDay = (new \DateTime("$year-$month-01"))->format('t');
        $timeMax = (new \DateTime("$year-$month-{$lastDay}T23:59:59"))->format(\DateTime::RFC3339);

        $params = [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ];

        $results = $this->calendarService->events->listEvents($calendarId, $params);
        $events = [];

        foreach ($results->getItems() as $item) {
            $start = $item->getStart();
            $end = $item->getEnd();

            // Eventos de todo el día usan 'date', los con hora usan 'dateTime'
            $startStr = $start->getDateTime() ?? $start->getDate();
            $endStr = $end->getDateTime() ?? $end->getDate();
            $allDay = ($start->getDateTime() === null);

            $events[] = [
                'id' => $item->getId(),
                'title' => $item->getSummary() ?? '(Sin título)',
                'description' => $item->getDescription() ?? '',
                'location' => $item->getLocation() ?? '',
                'start' => $startStr,
                'end' => $endStr,
                'allDay' => $allDay,
                'color' => $item->getColorId() ?? null,
            ];
        }

        return $events;
    }

    /**
     * Crea un evento nuevo en el calendario.
     */
    public function createEvent(string $calendarId, array $data): array
    {
        $event = new Event([
            'summary' => $data['title'] ?? 'Nuevo evento',
            'description' => $data['description'] ?? '',
            // Añadimos un Zero-Width Space al principio para que Google Calendar 
            // no detecte esto como una dirección válida de Maps y no genere la previsualización, 
            // pero el texto se guarde y recupere en las apps.
            'location' => !empty($data['location']) ? "\u{200B}" . $data['location'] : '',
            'colorId' => $data['colorId'] ?? null,
        ]);

        if (isset($data['reminderMinutes']) && $data['reminderMinutes'] !== null) {
            $event->setReminders(new \Google\Service\Calendar\EventReminders([
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'popup', 'minutes' => (int) $data['reminderMinutes']],
                ],
            ]));
        }

        if (!empty($data['allDay'])) {
            // Evento de todo el día
            $startDate = (new \DateTime($data['start']))->format('Y-m-d');
            $endDate = !empty($data['end'])
                ? (new \DateTime($data['end']))->format('Y-m-d')
                : $startDate;

            $event->setStart(new EventDateTime(['date' => $startDate]));
            $event->setEnd(new EventDateTime(['date' => $endDate]));
        } else {
            // Evento con hora
            $tz = new \DateTimeZone('Europe/Madrid');
            $start = new EventDateTime([
                'dateTime' => (new \DateTime($data['start'], $tz))->format(\DateTime::RFC3339),
                'timeZone' => 'Europe/Madrid',
            ]);
            $end = new EventDateTime([
                'dateTime' => (new \DateTime($data['end'] ?? $data['start'], $tz))->format(\DateTime::RFC3339),
                'timeZone' => 'Europe/Madrid',
            ]);
            $event->setStart($start);
            $event->setEnd($end);
        }

        $created = $this->calendarService->events->insert($calendarId, $event);

        return [
            'id' => $created->getId(),
            'title' => $created->getSummary(),
            'start' => $created->getStart()->getDateTime() ?? $created->getStart()->getDate(),
        ];
    }

    /**
     * Actualiza un evento existente.
     */
    public function updateEvent(string $calendarId, string $eventId, array $data): array
    {
        $event = $this->calendarService->events->get($calendarId, $eventId);

        if (isset($data['title']))
            $event->setSummary($data['title']);
        if (isset($data['description']))
            $event->setDescription($data['description']);
        if (isset($data['location'])) {
            $event->setLocation(!empty($data['location']) ? "\u{200B}" . $data['location'] : '');
        }
        if (isset($data['colorId']))
            $event->setColorId($data['colorId']);

        if (array_key_exists('reminderMinutes', $data)) {
            if ($data['reminderMinutes'] !== null) {
                $event->setReminders(new \Google\Service\Calendar\EventReminders([
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'popup', 'minutes' => (int) $data['reminderMinutes']],
                    ],
                ]));
            } else {
                $event->setReminders(new \Google\Service\Calendar\EventReminders([
                    'useDefault' => true,
                ]));
            }
        }

        if (!empty($data['allDay'])) {
            $startDate = (new \DateTime($data['start']))->format('Y-m-d');
            $endDate = !empty($data['end']) ? (new \DateTime($data['end']))->format('Y-m-d') : $startDate;
            $event->setStart(new EventDateTime(['date' => $startDate]));
            $event->setEnd(new EventDateTime(['date' => $endDate]));
        } else {
            $tz = new \DateTimeZone('Europe/Madrid');
            $start = new EventDateTime([
                'dateTime' => (new \DateTime($data['start'], $tz))->format(\DateTime::RFC3339),
                'timeZone' => 'Europe/Madrid',
            ]);
            $end = new EventDateTime([
                'dateTime' => (new \DateTime($data['end'] ?? $data['start'], $tz))->format(\DateTime::RFC3339),
                'timeZone' => 'Europe/Madrid',
            ]);
            $event->setStart($start);
            $event->setEnd($end);
        }

        $updated = $this->calendarService->events->update($calendarId, $eventId, $event);

        return [
            'id' => $updated->getId(),
            'title' => $updated->getSummary(),
            'start' => $updated->getStart()->getDateTime() ?? $updated->getStart()->getDate(),
        ];
    }

    /**
     * Elimina un evento por su ID.
     */
    public function deleteEvent(string $calendarId, string $eventId): bool
    {
        try {
            $this->calendarService->events->delete($calendarId, $eventId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
