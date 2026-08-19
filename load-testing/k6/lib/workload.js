export const SCALE_MATRIX = Object.freeze({
  10: 1,
  30: 2,
  50: 2,
  100: 5,
  200: 10,
  300: 20,
  400: 20,
  500: 20,
});

export function sessionsForStudents(students) {
  return SCALE_MATRIX[students] ?? null;
}

export function balancedDistribution(students, sessions) {
  if (!Number.isInteger(students) || students < 1) {
    throw new Error('students must be a positive integer');
  }
  if (!Number.isInteger(sessions) || sessions < 1 || sessions > students) {
    throw new Error('sessions must be between one and students');
  }

  const minimum = Math.floor(students / sessions);
  const remainder = students % sessions;
  const distribution = Array.from(
    { length: sessions },
    (_, index) => minimum + (index < remainder ? 1 : 0),
  );

  if (distribution.reduce((total, value) => total + value, 0) !== students) {
    throw new Error('balanced distribution lost a student');
  }

  return distribution;
}

export function deterministicUnit(seed, playerIndex, questionIndex, purpose) {
  const input = `${seed}|${playerIndex}|${questionIndex}|${purpose}`;
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619) >>> 0;
  }

  return hash / 4294967296;
}

export function answerDelayMs(mode, seed, playerIndex, questionIndex, timing) {
  const value = deterministicUnit(seed, playerIndex, questionIndex, 'answer-delay');

  if (mode === 'BURST') {
    const majority = deterministicUnit(seed, playerIndex, questionIndex, 'burst-majority')
      < timing.burstMajorityRatio;
    const minimum = majority ? timing.burstMinimumMs : timing.burstTailMinimumMs;
    const maximum = majority ? timing.burstMaximumMs : timing.burstTailMaximumMs;
    return Math.round(minimum + value * (maximum - minimum));
  }

  return Math.round(
    timing.classroomMinimumMs
      + value * (timing.classroomMaximumMs - timing.classroomMinimumMs),
  );
}

export function shouldAnswerCorrectly(ratio, seed, playerIndex, questionIndex) {
  return deterministicUnit(seed, playerIndex, questionIndex, 'answer-correctness') < ratio;
}

export function validIncorrectSelection(question) {
  const correct = [...question.correctOptionIds];
  const incorrect = question.optionIds.filter((optionId) => !correct.includes(optionId));

  if (question.questionType !== 'MULTIPLE_CHOICE') {
    if (incorrect.length < 1) throw new Error('question has no incorrect option');
    return [incorrect[0]];
  }

  if (incorrect.length < 1 || correct.length < 2) {
    throw new Error('multiple-choice fixture cannot produce a valid incorrect answer');
  }

  const selection = [...correct];
  selection[selection.length - 1] = incorrect[0];
  return selection;
}
