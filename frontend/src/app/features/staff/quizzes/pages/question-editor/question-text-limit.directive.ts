import { Directive } from '@angular/core';

import { QUESTION_TEXT_MAX_LENGTH } from '../../data-access/questions.models';

@Directive({
  selector: 'textarea[clqQuestionTextLimit]',
  host: { '[attr.maxlength]': 'maxLength' },
})
export class QuestionTextLimitDirective {
  protected readonly maxLength = QUESTION_TEXT_MAX_LENGTH;
}
