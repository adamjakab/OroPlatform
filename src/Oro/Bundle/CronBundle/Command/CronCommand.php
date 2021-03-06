<?php

namespace Oro\Bundle\CronBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use JMS\JobQueueBundle\Entity\Job;

use Oro\Bundle\CronBundle\Command\CronCommandInterface;
use Oro\Bundle\CronBundle\Entity\Schedule;

class CronCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('oro:cron')
            ->setDescription('Cron commands launcher');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // check for maintenance mode - do not run cron jobs if it is switched on
        if ($this->getContainer()->get('oro_platform.maintenance')->isOn()) {
            $output->writeln('');
            $output->writeln('<error>System is in maintenance mode, aborting</error>');

            return;
        }

        $commands   = $this->getApplication()->all('oro:cron');
        $em         = $this->getContainer()->get('doctrine.orm.entity_manager');
        $daemon     = $this->getContainer()->get('oro_cron.job_daemon');
        $schedules  = $em->getRepository('OroCronBundle:Schedule')->findAll();

        // check if daemon is running
        if (!$daemon->getPid()) {
            $output->writeln('');
            $output->write('Daemon process not found, running.. ');

            if ($pid = $daemon->run()) {
                $output->writeln(sprintf('<info>OK</info> (pid: %u)', $pid));
            } else {
                $output->writeln('<error>failed</error>. Cron jobs can\'t be launched.');

                return;
            }
        }

        foreach ($commands as $name => $command) {
            $output->write(sprintf('Processing command "<info>%s</info>": ', $name));

            if (!$command instanceof CronCommandInterface) {
                $output->writeln(
                    '<error>Unable to setup, command must be instance of CronCommandInterface</error>'
                );

                continue;
            }

            if (!$command->getDefaultDefinition()) {
                $output->writeln('<error>no cron definition found, check command</error>');

                continue;
            }

            $schedule = array_filter(
                $schedules,
                function ($element) use ($name) {
                    return $element->getCommand() == $name;
                }
            );

            if (empty($schedule)) {
                $output->writeln('<comment>new command found, setting up schedule..</comment>');

                $schedule = new Schedule();
                $schedule
                    ->setCommand($name)
                    ->setDefinition($command->getDefaultDefinition());

                $em->persist($schedule);

                continue;
            }

            $schedule = current($schedule);

            $defaultDefinition = $command->getDefaultDefinition();
            if ($schedule->getDefinition() != $defaultDefinition) {
                $schedule->setDefinition($defaultDefinition);
            }

            $cron = \Cron\CronExpression::factory($schedule->getDefinition());

            /**
             * @todo Add "Oro timezone" setting as parameter to isDue method
             */
            if ($cron->isDue()) {
                $job = new Job($name);

                $em->persist($job);

                $output->writeln('<comment>added to job queue</comment>');
            } else {
                $output->writeln('<comment>skipped</comment>');
            }
        }

        $em->flush();

        $output->writeln('');
        $output->writeln('All commands finished');
    }
}
