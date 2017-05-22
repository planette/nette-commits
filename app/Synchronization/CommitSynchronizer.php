<?php

declare(strict_types = 1);

namespace App\Synchronization;

use Milo\Github\Api;
use App\Entity\Commit;
use App\Entity\CommitFile;
use App\Entity\Repository;
use App\Facade\Commit\CommitPersister;
use App\Facade\Commit\CommitsOrderUpdater;
use App\Facade\Commit\UnreachableCommitsDeleter;
use App\QueryFunction\Commit\CommitsRepositoryShaMapQuery;
use App\QueryFunction\Repository\RepositoriesSortedByProjectAndNameQuery;


final class CommitSynchronizer
{

	private RepositoriesSortedByProjectAndNameQuery $repositoriesQuery;
	private CommitsRepositoryShaMapQuery $shaMapQuery;
	private CommitsOrderUpdater $commitsOrderUpdater;
	private UnreachableCommitsDeleter $unreachableCommitsDeleter;
	private Api $github;
	private UserSynchronizer $userSynchronizer;
	private CommitPersister $commitPersister;

	/**
	 * <repository_name> => [<commit_1_sha> => <commit_1_sort>, <commit_2_sha> => <commit_2_sort>, ...]
	 * @var array<string, array<string, int>>|null
	 */
	private ?array $shaMap = null;


	public function __construct(
		RepositoriesSortedByProjectAndNameQuery $repositoriesQuery,
		CommitsRepositoryShaMapQuery $shaMapQuery,
		CommitsOrderUpdater $commitsOrderUpdater,
		UnreachableCommitsDeleter $unreachableCommitsDeleter,
		Api $github,
		UserSynchronizer $userSynchronizer,
		CommitPersister $commitPersister

	) {
		$this->github = $github;
		$this->shaMapQuery = $shaMapQuery;
		$this->commitPersister = $commitPersister;
		$this->userSynchronizer = $userSynchronizer;
		$this->repositoriesQuery = $repositoriesQuery;
		$this->commitsOrderUpdater = $commitsOrderUpdater;
		$this->unreachableCommitsDeleter = $unreachableCommitsDeleter;
	}


	public function synchronize(): void
	{
		$repositories = $this->repositoriesQuery->get();

		foreach ($repositories as $repository) {
			$this->synchronizeRepository($repository);
		}
	}


	private function synchronizeRepository(Repository $repository): void
	{
		$paginator = $this->github->paginator(sprintf('/repos/%s/commits', $repository->getName()), [
			'per_page' => 100,
		]);

		$index = 0;
		$allSHAs = [];

		foreach ($paginator as $response) {
			$commits = $this->github->decode($response);

			foreach ($commits as $commit) {
				$allSHAs[] = $commit->sha;

				if (!$this->existsCommit($repository, $commit->sha)) {
					$this->synchronizeCommit($repository, $commit->sha, $index);
				}

				if (++$index % 1000 === 0) {
					$this->commitPersister->flush();
				}
			}
		}

		$this->commitPersister->flush();

		$this->unreachableCommitsDeleter->delete($repository, $allSHAs);
		$this->commitsOrderUpdater->update($repository, $allSHAs);
	}


	private function existsCommit(Repository $repository, string $sha): bool
	{
		if ($this->shaMap === null) {
			$this->shaMap = $this->shaMapQuery->get();
		}

		return isset($this->shaMap[$repository->getID()][$sha]);
	}


	private function synchronizeCommit(
		Repository $repository,
		string $sha,
		int $index

	): void {
		$response = $this->github->get(sprintf('/repos/%s/commits/:sha', $repository->getName()), [
			'sha' => $sha,
		]);

		$remoteCommit = $this->github->decode($response);

		$author = $committer = null;
		if (isset($remoteCommit->author)) {
			$author = $this->userSynchronizer->synchronize(
				$remoteCommit->author->id,
				$remoteCommit->author->login,
				$remoteCommit->author->avatar_url
			);
		}

		if (isset($remoteCommit->committer)) {
			$committer = $this->userSynchronizer->synchronize(
				$remoteCommit->committer->id,
				$remoteCommit->committer->login,
				$remoteCommit->committer->avatar_url
			);
		}

		$timezone = new \DateTimeZone(date_default_timezone_get());
		$authoredAt = new \DateTimeImmutable($remoteCommit->commit->author->date, $timezone);
		$committedAt = new \DateTimeImmutable($remoteCommit->commit->committer->date, $timezone);

		$localCommit = new Commit(
			$repository,
			$sha,

			$author,
			$remoteCommit->commit->author->name,
			$authoredAt,

			$committer,
			$remoteCommit->commit->committer->name,
			$committedAt,

			$remoteCommit->commit->message,

			$remoteCommit->stats->additions,
			$remoteCommit->stats->deletions,
			$remoteCommit->stats->total,

			$index
		);

		foreach ($remoteCommit->files as $remoteFile) {
			new CommitFile(
				$localCommit,
				$remoteFile->filename,

				$remoteFile->status,
				$remoteFile->additions,
				$remoteFile->deletions,
				$remoteFile->changes
			);
		}

		$this->commitPersister->persistWithoutFlush($localCommit);
	}

}
