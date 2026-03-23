<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\db\Query;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\records\BackfillLog;
use yii\base\Component;

class BackfillLogs extends Component
{
	public const LOG_LIMIT = 500;

	/**
	 * @return list<array{id: int, level: string, type: string, reference: string, message: string, dateCreated: string}>
	 */
	public function getAll(?string $level = null): array
	{
		$query = $this->_createQuery()
			->orderBy([
				'dateCreated' => SORT_DESC,
			])
			->limit(self::LOG_LIMIT);

		if ($level !== null) {
			$query->andWhere([
				'level' => $level,
			]);
		}

		/** @var list<array{id: int, level: string, type: string, reference: string, message: string, dateCreated: string}> */
		return $query->all();
	}

	public function log(string $type, string $reference, string $message, string $level = 'error'): void
	{
		$record = new BackfillLog();
		$record->level = $level;
		$record->type = $type;
		$record->reference = $reference;
		$record->message = $message;
		$record->save(false);
	}

	public function deleteById(int $id): bool
	{
		$record = BackfillLog::findOne($id);
		if ($record === null) {
			return false;
		}

		return (bool) $record->delete();
	}

	public function deleteAll(): int
	{
		return BackfillLog::deleteAll();
	}

	public function hasLogs(): bool
	{
		return BackfillLog::find()->exists();
	}

	/**
	 * Prune old log entries beyond the limit.
	 */
	public function prune(): void
	{
		$threshold = (new Query())
			->select('dateCreated')
			->from(Table::BACKFILL_LOGS)
			->orderBy([
				'dateCreated' => SORT_DESC,
			])
			->offset(self::LOG_LIMIT)
			->limit(1)
			->scalar();

		if ($threshold === false) {
			return;
		}

		Craft::$app->db->createCommand()
			->delete(Table::BACKFILL_LOGS, ['<=', 'dateCreated', $threshold])
			->execute();
	}

	/**
	 * @return Query<array-key, mixed>
	 */
	private function _createQuery(): Query
	{
		return (new Query())
			->select(['id', 'level', 'type', 'reference', 'message', 'dateCreated'])
			->from(Table::BACKFILL_LOGS);
	}
}
