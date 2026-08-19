import assert from 'node:assert/strict';
import test from 'node:test';

import {
  answerDelayMs,
  balancedDistribution,
  sessionsForStudents,
  shouldAnswerCorrectly,
  validIncorrectSelection,
} from './workload.js';

test('future matrix retains the requested classroom topology', () => {
  assert.equal(sessionsForStudents(10), 1);
  assert.equal(sessionsForStudents(300), 20);
  assert.equal(sessionsForStudents(500), 20);
  assert.equal(sessionsForStudents(17), null);
});

test('arbitrary distribution is balanced and exact', () => {
  assert.deepEqual(balancedDistribution(10, 3), [4, 3, 3]);
  assert.deepEqual(balancedDistribution(500, 20), Array(20).fill(25));
  assert.equal(balancedDistribution(31, 2).reduce((sum, value) => sum + value, 0), 31);
});

test('timing and correctness choices are reproducible', () => {
  const timing = {
    classroomMinimumMs: 1000,
    classroomMaximumMs: 5500,
    burstMajorityRatio: 0.85,
    burstMinimumMs: 250,
    burstMaximumMs: 1800,
    burstTailMinimumMs: 1800,
    burstTailMaximumMs: 3200,
  };
  assert.equal(answerDelayMs('CLASSROOM', 42, 7, 2, timing), answerDelayMs('CLASSROOM', 42, 7, 2, timing));
  assert.equal(shouldAnswerCorrectly(0.7, 42, 7, 2), shouldAnswerCorrectly(0.7, 42, 7, 2));
});

test('incorrect multiple choice remains structurally valid', () => {
  const selection = validIncorrectSelection({
    questionType: 'MULTIPLE_CHOICE',
    optionIds: [1, 2, 3, 4],
    correctOptionIds: [1, 3],
  });
  assert.equal(selection.length, 2);
  assert.notDeepEqual(selection.sort(), [1, 3]);
});
