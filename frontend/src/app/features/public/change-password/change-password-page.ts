import { NgOptimizedImage } from '@angular/common';
import { Component } from '@angular/core';

import { ChangePasswordForm } from '../../../shared/account/change-password-form/change-password-form';

@Component({
  selector: 'clq-change-password-page',
  imports: [NgOptimizedImage, ChangePasswordForm],
  templateUrl: './change-password-page.html',
  styleUrl: './change-password-page.scss',
})
export class ChangePasswordPage {}
