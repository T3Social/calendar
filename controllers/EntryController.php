<?php

namespace module\calendar\controllers;

use DateTime;
use DateInterval;
use Yii;
use yii\web\HttpException;
use humhub\modules\user\models\User;
use humhub\modules\user\widgets\UserListBox;
use humhub\modules\content\components\ContentContainerController;
use humhub\models\Setting;
use module\calendar\models\CalendarEntry;
use module\calendar\models\CalendarEntryParticipant;

/**
 * EntryController used to display, edit or delete calendar entries
 *
 * @package humhub.modules_core.calendar.controllers
 * @author luke
 */
class EntryController extends ContentContainerController
{

    public function actionView()
    {
        $calendarEntry = $this->getCalendarEntry(Yii::$app->request->get('id'));
        if ($calendarEntry == null) {
            throw new HttpException('404', Yii::t('CalendarModule.base', "Event not found!"));
        }

        $calendarEntryParticipant = CalendarEntryParticipant::findOne(['user_id' => Yii::$app->user->id, 'calendar_entry_id' => $calendarEntry->id]);

        return $this->render('view', array(
                    'calendarEntry' => $calendarEntry,
                    'calendarEntryParticipant' => $calendarEntryParticipant,
                    'userCanRespond' => $calendarEntry->canRespond(),
                    'userAlreadyResponded' => $calendarEntry->hasResponded(),
                    'contentContainer' => $this->contentContainer,
        ));
    }

    public function actionRespond()
    {
        $calendarEntry = $this->getCalendarEntry(Yii::$app->request->get('id'));

        if ($calendarEntry == null) {
            throw new HttpException('404', Yii::t('CalendarModule.base', "Event not found!"));
        }

        if ($calendarEntry->canRespond()) {
            $calendarEntryParticipant = CalendarEntryParticipant::findOne(['calendar_entry_id' => $calendarEntry->id, 'user_id' => Yii::$app->user->id]);

            if ($calendarEntryParticipant == null) {
                $calendarEntryParticipant = new CalendarEntryParticipant;
                $calendarEntryParticipant->user_id = Yii::$app->user->id;
                $calendarEntryParticipant->calendar_entry_id = $calendarEntry->id;
            }

            $calendarEntryParticipant->participation_state = (int) Yii::$app->request->get('type');
            $calendarEntryParticipant->save();
        }

        return $this->redirect($this->contentContainer->createUrl('view', array('id' => $calendarEntry->id)));
    }

    public function actionEdit()
    {
        $calendarEntry = $this->getCalendarEntry(Yii::$app->request->get('id'));

        if ($calendarEntry == null) {

            $calendarEntry = new CalendarEntry;
            $calendarEntry->content->container = $this->contentContainer;

            if (Yii::$app->request->get('fullCalendar') == 1) {
                \module\calendar\widgets\FullCalendar::populate($calendarEntry);
            }
        }

        if ($calendarEntry->load(Yii::$app->request->post()) && $calendarEntry->validate() && $calendarEntry->save()) {
            // After closing modal refresh calendar or page
            $output = "<script>";
            $output .= 'if(typeof $("#calendar").fullCalendar != "undefined") { $("#calendar").fullCalendar("refetchEvents"); } else { location.reload(); }';
            $output .= "</script>";

            $output .= $this->renderModalClose();

            return $this->renderAjaxContent($output);
        }

        return $this->renderAjax('edit', [
                    'calendarEntry' => $calendarEntry,
                    'contentContainer' => $this->contentContainer,
                    'createFromGlobalCalendar' => false
        ]);
    }

    public function actionUserList()
    {
        $calendarEntry = $this->getCalendarEntry(Yii::$app->request->get('id'));

        if ($calendarEntry == null) {
            throw new HttpException('404', Yii::t('CalendarModule.base', "Event not found!"));
        }
        $state = Yii::$app->request->get('state');

        $query = User::find();
        $query->leftJoin('calendar_entry_participant', 'user.id=calendar_entry_participant.user_id AND calendar_entry_participant.calendar_entry_id=:calendar_entry_id AND calendar_entry_participant.participation_state=:state', [
            ':calendar_entry_id' => $calendarEntry->id,
            ':state' => $state
        ]);
        $query->where('calendar_entry_participant.id IS NOT NULL');

        $title = "";
        if ($state == CalendarEntryParticipant::PARTICIPATION_STATE_ACCEPTED) {
            $title = Yii::t('CalendarModule.base', 'Attending users');
        } elseif ($state == CalendarEntryParticipant::PARTICIPATION_STATE_DECLINED) {
            $title = Yii::t('CalendarModule.base', 'Declining users');
        } elseif ($state == CalendarEntryParticipant::PARTICIPATION_STATE_MAYBE) {
            $title = Yii::t('CalendarModule.base', 'Maybe attending users');
        }
        return $this->renderAjaxContent(UserListBox::widget(['query' => $query, 'title' => $title]));
    }

    public function actionEditAjax()
    {
        $calendarEntry = $this->getCalendarEntry(Yii::$app->request->get('id'));

        if ($calendarEntry == null) {
            throw new HttpException('404', Yii::t('CalendarModule.base', "Event not found!"));
        }

        if (!$calendarEntry->content->canWrite()) {
            throw new HttpException('403', Yii::t('CalendarModule.base', "You don't have permission to edit this event!"));
        }

        if (Yii::$app->request->get('fullCalendar') == 1) {
            \module\calendar\widgets\FullCalendar::populate($calendarEntry);
        }

        if ($calendarEntry->validate() && $calendarEntry->save()) {
            return;
        }

        throw new HttpException("Could not save!" . print_r($calendarEntry->getErrors(), 1));
    }

    public function actionDelete()
    {
        $calendarEntry = $this->getCalendarEntry(Yii::$app->request->get('id'));

        if ($calendarEntry == null) {
            throw new HttpException('404', Yii::t('CalendarModule.base', "Event not found!"));
        }

        if (!$calendarEntry->content->canDelete()) {
            throw new HttpException('403', Yii::t('CalendarModule.base', "You don't have permission to delete this event!"));
        }

        $calendarEntry->delete();

        if (Yii::$app->request->isAjax) {
            return $this->renderModalClose();
        } else {
            return $this->redirect($this->contentContainer->createUrl('/calendar/view/index'));
        }
    }

    /**
     * Returns a readable calendar entry by given id
     * 
     * @param int $id
     * @return CalendarEntry
     */
    protected function getCalendarEntry($id)
    {
        return CalendarEntry::find()->contentContainer($this->contentContainer)->readable()->where(['calendar_entry.id' => $id])->one();
    }

}
